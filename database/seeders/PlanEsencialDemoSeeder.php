<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Accounting\CloseAccountingPeriod;
use App\Http\Controllers\AccountingDocumentController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SalesController;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\Accounting\AccountReconciliationService;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\CommercialDocumentService;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\Fiscal\SatDteImportService;
use App\Services\Fiscal\VatBookService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Treasury\TreasuryBankService;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\ReviewPolicyService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Support\SpanishMonth;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Escenario integral de demostración del Plan Esencial: la oficina «Contadores Asociados del Atlántico» de Puerto
 * Barrios, con su propietario y una contadora encargada (los dos asientos del plan), una suscripción activa al plan
 * Esencial y diez empresas cliente de Izabal con tres meses de contabilidad.
 *
 * Todo pasa por las mismas acciones y servicios que usan las pantallas: los asientos automáticos, el IVA, los saldos,
 * los libros y los reportes salen de los documentos registrados, nunca de cifras escritas a mano. Las operaciones
 * externas (FEL, pagos, correos, bancos) no se ejecutan. Volver a ejecutarlo no duplica nada: cada registro tiene una
 * llave determinística y se busca antes de crearlo. Solo toca su propia organización.
 *
 * @phpstan-import-type DemoProfile from PlanEsencialDemoCatalog
 */
class PlanEsencialDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const BANK_FEE = '45.00';

    private const PETTY_CASH = '1500.00';

    private Organization $organization;

    /** La organización cargada desde el propietario (con su pivote de rol), como la usan las pantallas. */
    private Organization $member;

    private User $owner;

    private User $collaborator;

    private int $officeId;

    /** Quien lleva los libros de la empresa que se está cargando: su contadora encargada o el propietario. */
    private User $bookkeeper;

    /** @var array<string, int> Cuentas contables de la empresa por código. */
    private array $accounts = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{0: string, 1: string, 2: string}> Usuarios creados en esta ejecución con su contraseña aleatoria (correo, nombre, contraseña). */
    private array $credentials = [];

    public function run(): void
    {
        $this->refuseInProductionUnlessAllowed();
        $this->call(PlanCatalogSeeder::class);
        // Nada sale del servidor: correos, notificaciones, trabajos en cola y llamadas HTTP externas se simulan.
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 404)]);

        DB::transaction(fn () => $this->firm());
        $this->member = $this->owner->organizations()->whereKey($this->organization->id)->firstOrFail();

        $companies = $this->clients();
        $months = $this->months();
        foreach ($companies as [$company, $profile]) {
            $this->attempt($company, 'la contabilidad del trimestre', fn () => $this->books($company, $profile, $months));
        }
        foreach ($companies as $index => [$company]) {
            $this->attempt($company, 'el cruce con la SAT', fn () => $this->satCrossCheck($company, $months[1]));
            $this->attempt($company, 'las partidas en revisión', fn () => $this->journalWorkflow($company, $index, $months[2]));
            $this->attempt($company, 'las cotizaciones y órdenes', fn () => $this->commercialDocuments($company, $index, $months[2]));
        }

        $ids = array_map(fn (array $pair): int => (int) $pair[0]->id, $companies);
        $this->obligations($companies, $months);
        $this->tasks($companies, $months);
        $this->report($ids);
    }

    /** La oficina contable, su equipo de dos personas, la suscripción al plan Esencial y los roles permitidos por el plan. */
    private function firm(): void
    {
        $firm = PlanEsencialDemoCatalog::firm();
        $team = PlanEsencialDemoCatalog::team();
        $this->owner = $this->user($team['owner']['email'], $team['owner']['name']);
        $this->collaborator = $this->user($team['collaborator']['email'], $team['collaborator']['name']);

        $organization = Organization::query()->withTrashed()->firstOrNew(['country_code' => 'GT', 'tax_identifier' => PlanEsencialDemoCatalog::ORGANIZATION_TAX_ID]);
        $organization->fill(['name' => $firm['name'], 'legal_name' => $firm['legal_name'], 'currency_code' => 'GTQ', 'timezone' => 'America/Guatemala', 'status' => 'active']);
        $organization->deleted_at = null;
        $organization->save();
        $this->organization = $organization;

        // El plan admite dos usuarios de oficina: si ya hay otros miembros activos, el colaborador no se agrega.
        $others = DB::table('organization_user')->where('organization_id', $organization->id)->where('status', 'active')
            ->whereNotIn('user_id', [$this->owner->id, $this->collaborator->id])->count();
        if ($others > 0) {
            throw new RuntimeException('La organización de demostración ya tiene otros usuarios activos; el Plan Esencial solo admite dos.');
        }
        foreach ([[$this->owner, 'owner', 'manager'], [$this->collaborator, 'member', 'member']] as [$user, $organizationRole]) {
            DB::table('organization_user')->updateOrInsert(['organization_id' => $organization->id, 'user_id' => $user->id],
                ['role' => $organizationRole, 'status' => 'active', 'joined_at' => now()->subMonths(8), 'invited_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $office = $firm['office'];
        $officeId = DB::table('offices')->where('organization_id', $organization->id)->where('is_primary', true)->value('id');
        $this->officeId = $officeId !== null ? (int) $officeId : (int) DB::table('offices')->insertGetId(['organization_id' => $organization->id, 'public_id' => (string) Str::uuid(),
            'code' => $office['code'], 'name' => $office['name'], 'email' => $office['email'], 'phone' => $office['phone'], 'address_line_1' => $office['address'],
            'city' => $office['city'], 'state' => $office['state'], 'country_code' => 'GT', 'timezone' => 'America/Guatemala', 'is_primary' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$this->owner, 'manager'], [$this->collaborator, 'member']] as [$user, $officeRole]) {
            DB::table('office_user')->updateOrInsert(['office_id' => $this->officeId, 'user_id' => $user->id],
                ['role' => $officeRole, 'status' => 'active', 'assigned_at' => now()->subMonths(8), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->subscription($organization);
        app(WorkAuthorizationService::class)->ensureDefaults($organization);
        $this->assignRole($this->owner->id, 'owner_director');
        // Sin el módulo de roles y permisos, el segundo asiento solo puede ser Contador Encargado o Auxiliar Contable.
        $this->assignRole($this->collaborator->id, 'accountant_in_charge');
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = User::query()->create(['email' => $email, 'name' => $name, 'password' => $this->newPassword($email, $name)]);
        } else {
            $user->forceFill(['name' => $name])->save();
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $user;
    }

    /** Contraseña aleatoria para un usuario nuevo: se muestra una sola vez al final y en la base queda solo su hash. */
    private function newPassword(string $email, string $name): string
    {
        $password = Str::password(16, symbols: false);
        $this->credentials[] = [$email, $name, $password];

        return $password;
    }

    /** Suscripción manual activa al precio mensual del plan Esencial, ligada a su versión publicada del catálogo. */
    private function subscription(Organization $organization): void
    {
        [$priceId, $versionId] = app(TenantDatabaseContext::class)->runAsSystem(fn (): array => [
            (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', 'essential')
                ->where('plan_prices.billing_period', 'monthly')->where('plan_prices.currency_code', 'GTQ')->value('plan_prices.id'),
            DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')->where('plans.code', 'essential')
                ->where('plan_versions.status', 'published')->orderByDesc('plan_versions.version')->value('plan_versions.id'),
        ]);
        if ($priceId === 0) {
            throw new RuntimeException('No existe el precio mensual del plan Esencial. Ejecuta PlanCatalogSeeder.');
        }
        $subscription = Subscription::query()->firstOrNew(['organization_id' => $organization->id, 'provider' => 'manual', 'provider_subscription_id' => 'demo-plan-esencial-'.$organization->id]);
        $subscription->fill(['provider_customer_id' => 'demo-plan-esencial-'.$organization->id, 'status' => 'active', 'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->endOfMonth(), 'cancel_at_period_end' => false])->save();
        if ($versionId !== null) {
            DB::table('subscriptions')->where('id', $subscription->id)->update(['plan_version_id' => (int) $versionId]);
        }
        SubscriptionItem::query()->updateOrCreate(['subscription_id' => $subscription->id, 'item_type' => 'base_plan'],
            ['plan_price_id' => $priceId, 'quantity' => 1, 'provider_item_id' => 'demo-plan-esencial-item-'.$organization->id]);
    }

    private function assignRole(int $userId, string $roleCode): void
    {
        $roleId = (int) DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('code', $roleCode)->whereNull('deleted_at')->value('id');
        DB::table('user_role_assignments')->where('organization_id', $this->organization->id)->where('user_id', $userId)->where('status', 'active')
            ->where('workspace_role_id', '<>', $roleId)->update(['status' => 'inactive', 'ends_at' => now(), 'updated_at' => now()]);
        DB::table('user_role_assignments')->updateOrInsert(['organization_id' => $this->organization->id, 'user_id' => $userId, 'workspace_role_id' => $roleId],
            ['office_id' => $roleCode === 'owner_director' ? null : $this->officeId, 'assigned_by' => $this->owner->id, 'status' => 'active', 'starts_at' => now()->subMonths(8),
                'ends_at' => null, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null]);
    }

    /**
     * Las diez empresas cliente, creadas con el mismo servicio de la pantalla Clientes (catálogo, perfil fiscal e impuestos
     * incluidos, y el límite de 150 empresas del plan).
     *
     * @return list<array{0: stdClass, 1: DemoProfile}>
     */
    private function clients(): array
    {
        $service = app(CompanyAdministrationService::class);
        $clients = [];
        foreach (PlanEsencialDemoCatalog::companies() as $profile) {
            $company = DB::table('companies')->where('organization_id', $this->organization->id)->where('tax_id', $profile['tax_id'])->first();
            if ($company === null) {
                $created = $service->create($this->member, $this->owner, ['name' => $profile['name'], 'tax_id' => $profile['tax_id'], 'email' => $profile['email'],
                    'office_id' => $this->officeId, 'accountant_id' => $profile['owner_books'] ? null : $this->collaborator->id]);
                // Son clientes que la oficina ya atendía: sus meses anteriores ya estaban declarados.
                DB::table('companies')->where('id', $created['id'])->update(['trade_name' => $profile['trade_name'], 'phone' => $profile['phone'], 'address' => $profile['address'],
                    'legal_representative' => $profile['representative'], 'contact_name' => $profile['contact'], 'created_at' => now()->subMonths(6)]);
                $company = DB::table('companies')->where('id', $created['id'])->first();
            }
            if ($company !== null) {
                $clients[] = [$company, $profile];
            }
        }

        return $clients;
    }

    /**
     * Tres meses de contabilidad: apertura, ventas al crédito y al contado, compras, cobros, pagos, movimientos del banco,
     * notas de crédito, conciliación, libros de IVA cerrados y el primer período cerrado con su checklist real.
     *
     * @param  DemoProfile  $profile
     * @param  list<CarbonImmutable>  $months
     */
    private function books(stdClass $company, array $profile, array $months): void
    {
        $this->bookkeeper = $this->bookkeeperOf($company);
        $automation = app(AccountingAutomationService::class);
        $mapping = $automation->ensureMapping((int) $company->id);
        $this->accounts = DB::table('ledger_accounts')->where('company_id', $company->id)->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        if ($mapping !== null && $profile['revenue'] !== '4101') {
            $automation->updateMapping((int) $company->id, ['cash_account_id' => $mapping->cash_account_id, 'receivable_account_id' => $mapping->receivable_account_id,
                'revenue_account_id' => $this->account($profile['revenue']), 'payable_account_id' => $mapping->payable_account_id,
                'default_expense_account_id' => $mapping->default_expense_account_id]);
        }

        $this->openPeriods($company, $months);
        $this->closeEarlierVatBooks($company, $months[0]);
        $this->manualEntry($company, $this->owner, 'capital', $months[0]->toDateString(), 'opening', 'Saldo inicial: aporte de capital depositado en banco', 'APERTURA',
            [['account_id' => $this->account('1102'), 'debit' => $profile['capital'], 'credit' => '0'], ['account_id' => $this->account('3101'), 'debit' => '0', 'credit' => $profile['capital']]]);
        $customers = $this->customers($company, $profile['customers']);
        $suppliers = $this->suppliers($company, $profile['suppliers']);
        $bank = $this->bankAccount($company, $profile['bank'], $profile['capital'], $months[0]);

        foreach ($months as $index => $month) {
            $last = $index === count($months) - 1;
            foreach ($profile['sales'] as $number => [$customer, $day, $lines, $cash]) {
                $date = $this->day($month, $day);
                if ($date === null) {
                    continue;
                }
                $invoice = $this->sale($company, $customers[$customer], $date, $lines, sprintf('%s%02d', $month->format('ym'), $number + 1), $profile['series']);
                // Los meses cerrados se cobraron completos; el mes en curso deja saldos abiertos dentro de su plazo.
                $share = $cash ? 1.0 : ($last ? 0.5 : 1.0);
                $this->collect($company, $bank, $invoice, $cash ? $date : $this->paidOn($date, 6), $share, $cash);
            }
            $creditPurchases = array_keys(array_filter($profile['purchases'], fn (array $purchase): bool => ! $purchase[6]));
            foreach ($profile['purchases'] as $number => [$supplier, $day, $account, $treatment, $description, $amount, $cash]) {
                $date = $this->day($month, $day);
                if ($date === null) {
                    continue;
                }
                $document = $this->purchase($company, $suppliers[$supplier], $date, $account, $treatment, $description, $amount, sprintf('%s%02d', $month->format('ym'), $number + 1));
                if ($cash) {
                    $this->pay($company, $bank, $document, $date, true, true);
                } elseif (! $last || $day <= 10) {
                    // El último cheque del mes anterior aún no lo cobra el banco: queda en circulación para la conciliación.
                    $cashed = ! ($index === count($months) - 2 && $number === end($creditPurchases));
                    $this->pay($company, $bank, $document, $this->paidOn($date, 8), $cashed, false);
                }
            }
            if (! $last) {
                $this->bankFee($company, $bank, $month, $index === 0);
            }
            if ($index === 1) {
                $this->pettyCash($company, $bank, $month);
            }
        }

        $this->attempt($company, 'las notas de crédito', fn () => $this->creditNotes($company, $months[2]));
        $this->postDrafts($company);
        $this->attempt($company, 'la conciliación bancaria', fn () => $this->at($months[2]->setDay(5)->setTime(12, 0), fn () => $this->reconcile($company, $bank, $months[1])));
        foreach (array_slice($months, 0, -1) as $month) {
            $this->attempt($company, 'el libro de IVA de '.$month->format('m/Y'), fn () => $this->at($month->addMonth()->setDay(8)->setTime(17, 0), fn () => $this->closeVatBook($company, $month)));
        }
        $this->attempt($company, 'el cierre del período '.$months[0]->format('m/Y'), fn () => $this->closePeriod($company, $months[0]));
    }

    /** La contadora encargada de la empresa cuando la tiene; si no, los libros los lleva el propietario. */
    private function bookkeeperOf(stdClass $company): User
    {
        $id = DB::table('company_assignments')->where('company_id', $company->id)->where('assignment_role', 'accountant_in_charge')->where('status', 'active')
            ->whereNull('deleted_at')->where('user_id', '<>', $this->owner->id)->orderByDesc('is_primary')->value('user_id');

        return ($id === null ? null : User::query()->whereKey((int) $id)->first()) ?? $this->owner;
    }

    /** @param list<CarbonImmutable> $months */
    private function openPeriods(stdClass $company, array $months): void
    {
        foreach ($months as $month) {
            $covered = DB::table('accounting_periods')->where('company_id', $company->id)->where('starts_on', '<=', $month->toDateString())
                ->where('ends_on', '>=', $month->toDateString())->exists();
            if (! $covered) {
                DB::table('accounting_periods')->insert(['company_id' => $company->id, 'name' => 'Período '.$month->format('Y-m'), 'starts_on' => $month->toDateString(),
                    'ends_on' => $month->endOfMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** Los meses en que ya era cliente, antes de llevar sus libros en Atlantia, están declarados y sin movimientos. */
    private function closeEarlierVatBooks(stdClass $company, CarbonImmutable $firstMonth): void
    {
        $month = CarbonImmutable::parse((string) $company->created_at)->startOfMonth()->max($firstMonth->subMonths(4));
        while ($month->lessThan($firstMonth)) {
            $closing = $month;
            $this->attempt($company, 'el libro de IVA de '.$closing->format('m/Y'), fn () => $this->at($closing->addMonth()->setDay(8)->setTime(17, 0), fn () => $this->closeVatBook($company, $closing)));
            $month = $month->addMonth();
        }
    }

    /** @return list<CarbonImmutable> Dos meses que se cierran y el mes en curso. */
    private function months(): array
    {
        $current = CarbonImmutable::now()->startOfMonth();

        return [$current->subMonths(2), $current->subMonth(), $current];
    }

    private function day(CarbonImmutable $month, int $day): ?string
    {
        $date = $month->setDay(min($day, $month->daysInMonth));

        return $date->isAfter(CarbonImmutable::today()) ? null : $date->toDateString();
    }

    private function paidOn(string $date, int $days): string
    {
        $paid = CarbonImmutable::parse($date)->addDays($days);
        $today = CarbonImmutable::today();

        return ($paid->isAfter($today) ? $today : $paid)->toDateString();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null, 3: int}>  $rows
     * @return array<string, int>
     */
    private function customers(stdClass $company, array $rows): array
    {
        $ids = [];
        foreach ($rows as [$name, $taxId, $email, $terms]) {
            $id = DB::table('sales_customers')->where('company_id', $company->id)->where('name', $name)->value('id');
            if ($id === null) {
                $this->act($this->owner, SalesController::class, 'customer', ['name' => $name, 'tax_id' => $taxId, 'email' => $email, 'payment_terms_days' => $terms], $company);
                $id = DB::table('sales_customers')->where('company_id', $company->id)->where('name', $name)->value('id');
            }
            $ids[$name] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int}>  $rows
     * @return array<string, int>
     */
    private function suppliers(stdClass $company, array $rows): array
    {
        $ids = [];
        foreach ($rows as [$name, $taxId, $email, $phone, $terms]) {
            $id = DB::table('purchase_suppliers')->where('company_id', $company->id)->where('name', $name)->value('id');
            if ($id === null) {
                $this->act($this->owner, PurchaseController::class, 'supplier', ['name' => $name, 'tax_id' => $taxId, 'email' => $email, 'phone' => $phone,
                    'payment_terms_days' => $terms, 'isr_regime' => 'utilities'], $company);
                $id = DB::table('purchase_suppliers')->where('company_id', $company->id)->where('name', $name)->value('id');
            }
            $ids[$name] = (int) $id;
        }

        return $ids;
    }

    /** @param array{0: string, 1: string, 2: string} $bank */
    private function bankAccount(stdClass $company, array $bank, string $capital, CarbonImmutable $firstMonth): stdClass
    {
        [$bankName, $accountName, $lastFour] = $bank;
        $account = DB::table('bank_accounts')->where('company_id', $company->id)->where('last_four', $lastFour)->first();
        if ($account === null) {
            $this->act($this->owner, BankController::class, 'storeAccount', ['ledger_account_id' => $this->account('1102'), 'bank_name' => $bankName,
                'account_name' => $accountName, 'account_type' => 'checking', 'last_four' => $lastFour, 'bank_balance' => '0.00', 'currency' => 'GTQ'], $company);
            $account = DB::table('bank_accounts')->where('company_id', $company->id)->where('last_four', $lastFour)->firstOrFail();
            $this->bankMovement($company, $account, $firstMonth->toDateString(), 'DEP-APERTURA', 'Depósito del aporte inicial de capital', 'inflow', $capital);
        }

        return $account;
    }

    /** @param list<array{0: string, 1: int, 2: string, 3: string}> $lines */
    private function sale(stdClass $company, int $customerId, string $date, array $lines, string $number, string $series): stdClass
    {
        $requestId = $this->uuid($company, 'sale:'.$number.':'.$customerId);
        if (! DB::table('sales_invoices')->where('public_id', $requestId)->exists()) {
            // Facturas registradas para la demostración: la serie, el número y la autorización son ficticios, no certificados por la SAT.
            $this->at(CarbonImmutable::parse($date)->setTime(10, 20), fn () => $this->act($this->bookkeeper, SalesController::class, 'store', ['request_id' => $requestId,
                'customer_id' => $customerId, 'issued_on' => $date, 'fiscal_document_type' => 'FACT', 'fiscal_series' => $series, 'fiscal_number' => $number,
                'fiscal_authorization' => $this->authorization($company, 'sale:'.$number.':'.$customerId),
                'lines' => array_map(fn (array $line): array => ['description' => $line[0], 'quantity' => $line[1], 'unit_price' => $line[2], 'tax_treatment' => $line[3]], $lines)], $company));
        }
        $invoice = DB::table('sales_invoices')->where('public_id', $requestId)->firstOrFail();
        if ($invoice->status === 'draft') {
            $this->at(CarbonImmutable::parse($date)->setTime(10, 35), fn () => $this->act($this->owner, SalesController::class, 'process', ['action' => 'issue'], $company, ['invoice' => $requestId]));
        }

        return DB::table('sales_invoices')->where('public_id', $requestId)->firstOrFail();
    }

    private function collect(stdClass $company, stdClass $bank, stdClass $invoice, string $date, float $share, bool $cash): void
    {
        $requestId = $this->uuid($company, 'receipt:'.$invoice->public_id);
        if (DB::table('sales_receipts')->where('public_id', $requestId)->exists()) {
            return;
        }
        $amount = $this->decimal((int) round((int) $invoice->total_cents * $share));
        $reference = ($cash ? 'EFE-' : 'TRF-').$invoice->fiscal_number;
        $this->at(CarbonImmutable::parse($date)->setTime(15, 10), fn () => $this->act($this->bookkeeper, SalesController::class, 'receipt', ['request_id' => $requestId,
            'amount' => $amount, 'received_on' => $date, 'reference' => $reference], $company, ['invoice' => (string) $invoice->public_id]));
        $this->bankMovement($company, $bank, $date, $reference, $cash ? 'Depósito de ventas al contado' : 'Transferencia recibida de '.$invoice->customer_name, 'inflow', $amount);
    }

    private function purchase(stdClass $company, int $supplierId, string $date, string $account, string $treatment, string $description, string $amount, string $number): stdClass
    {
        $requestId = $this->uuid($company, 'purchase:'.$number.':'.$supplierId);
        $supplier = DB::table('purchase_suppliers')->where('id', $supplierId)->firstOrFail();
        if (! DB::table('purchase_documents')->where('public_id', $requestId)->exists()) {
            $this->at(CarbonImmutable::parse($date)->setTime(11, 40), fn () => $this->act($this->bookkeeper, PurchaseController::class, 'store', ['request_id' => $requestId,
                'supplier_id' => $supplierId, 'document_type' => $treatment === 'goods' && str_starts_with($account, '51') ? 'purchase' : 'expense', 'external_reference' => 'FAC-'.$number,
                'document_date' => $date, 'due_on' => CarbonImmutable::parse($date)->addDays((int) ($supplier->payment_terms_days ?? 15))->toDateString(),
                'fiscal_document_type' => 'FACT', 'fiscal_series' => $this->supplierSeries($supplier), 'fiscal_number' => $number,
                'fiscal_authorization' => $this->authorization($company, 'purchase:'.$number.':'.$supplierId),
                'lines' => [['account_id' => $this->account($account), 'description' => $description, 'quantity' => 1, 'unit_price' => $amount, 'tax_treatment' => $treatment]]], $company));
        }
        $document = DB::table('purchase_documents')->where('public_id', $requestId)->firstOrFail();
        if ($document->status === 'draft') {
            $this->at(CarbonImmutable::parse($date)->setTime(17, 0), fn () => $this->act($this->owner, PurchaseController::class, 'process', ['action' => 'approve'], $company, ['document' => $requestId]));
        }

        return DB::table('purchase_documents')->where('public_id', $requestId)->firstOrFail();
    }

    private function supplierSeries(stdClass $supplier): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $supplier->name)) ?? 'PROV', 0, 4));
    }

    private function pay(stdClass $company, stdClass $bank, stdClass $document, string $date, bool $cashed, bool $transfer): void
    {
        $requestId = $this->uuid($company, 'payment:'.$document->public_id);
        if (DB::table('purchase_payments')->where('public_id', $requestId)->exists()) {
            return;
        }
        $reference = ($transfer ? 'TRF-' : 'CHQ-').str_pad((string) $document->number, 5, '0', STR_PAD_LEFT);
        $amount = $this->decimal((int) $document->total_cents);
        $this->at(CarbonImmutable::parse($date)->setTime(16, 20), fn () => $this->act($this->owner, PurchaseController::class, 'payment', ['request_id' => $requestId,
            'amount' => $amount, 'paid_on' => $date, 'reference' => $reference], $company, ['document' => (string) $document->public_id]));
        // Un cheque en circulación llega al banco en los primeros días del mes siguiente.
        $clearedOn = $cashed ? $date : $this->paidOn(CarbonImmutable::parse($date)->startOfMonth()->addMonth()->toDateString(), 2);
        $this->bankMovement($company, $bank, $clearedOn, $reference, ($transfer ? 'Transferencia pagada a ' : 'Cheque pagado a ').$document->supplier_name, 'outflow', $amount);
    }

    /**
     * El banco cobra una comisión mensual. La del primer mes se clasifica en Bancos (asiento automático y movimiento
     * conciliado); la del segundo queda pendiente y aparece como partida de la conciliación.
     */
    private function bankFee(stdClass $company, stdClass $bank, CarbonImmutable $month, bool $classified): void
    {
        $reference = 'COM-'.$month->format('Ym');
        $this->bankMovement($company, $bank, $month->endOfMonth()->toDateString(), $reference, 'Comisión por manejo de cuenta', 'outflow', self::BANK_FEE);
        $movement = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->first();
        if ($classified && $movement !== null && $movement->reconciliation_status !== 'matched') {
            $this->at($month->endOfMonth()->setTime(18, 0), fn () => app(TreasuryBankService::class)->classify($this->organization, $this->owner, (int) $movement->id, 'bank_fee'));
        }
    }

    /** Retiro del banco para el fondo de caja chica: movimiento del estado de cuenta y su asiento con soporte. */
    private function pettyCash(stdClass $company, stdClass $bank, CarbonImmutable $month): void
    {
        $date = $this->day($month, 2);
        if ($date === null) {
            return;
        }
        $this->manualEntry($company, $this->owner, 'petty-cash:'.$month->format('Ym'), $date, 'standard', 'Retiro bancario para constituir el fondo de caja chica', 'RET-'.$month->format('Ym'),
            [['account_id' => $this->account('1101'), 'debit' => self::PETTY_CASH, 'credit' => '0'], ['account_id' => $this->account('1102'), 'debit' => '0', 'credit' => self::PETTY_CASH]]);
        $this->bankMovement($company, $bank, $date, 'RET-'.$month->format('Ym'), 'Retiro de efectivo para caja chica', 'outflow', self::PETTY_CASH);
    }

    private function bankMovement(stdClass $company, stdClass $bank, string $date, string $reference, string $description, string $flow, string $amount): void
    {
        if (DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->exists()) {
            return;
        }
        $this->act($this->owner, BankController::class, 'storeTransaction', ['account_id' => $bank->id, 'transaction_date' => $date, 'reference' => $reference,
            'description' => $description, 'flow' => $flow, 'amount' => $amount], $company);
    }

    /** Una nota de crédito a un cliente y otra de un proveedor sobre documentos del mes en curso con saldo pendiente. */
    private function creditNotes(stdClass $company, CarbonImmutable $month): void
    {
        $from = $month->toDateString();
        $invoice = DB::table('sales_invoices')->where('company_id', $company->id)->where('status', 'issued')->where('issued_on', '>=', $from)
            ->where('customer_tax_id', '<>', 'CF')->orderBy('issued_on')->orderBy('id')->first();
        if ($invoice !== null && ! DB::table('sales_credit_notes')->where('sales_invoice_id', $invoice->id)->exists()) {
            $collected = (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoice->id)->sum('amount_cents');
            $amount = intdiv((int) $invoice->total_cents, 10);
            if ($amount > 0 && $amount <= (int) $invoice->total_cents - $collected) {
                $issuedOn = $this->paidOn((string) $invoice->issued_on, 2);
                $this->at(CarbonImmutable::parse($issuedOn)->setTime(9, 30), fn () => $this->act($this->owner, SalesController::class, 'creditNote', ['amount' => $this->decimal($amount),
                    'issued_on' => $issuedOn, 'reason' => 'Descuento comercial del 10 % acordado con el cliente por volumen de compra'], $company, ['invoice' => (string) $invoice->public_id]));
            }
        }

        $document = DB::table('purchase_documents')->where('company_id', $company->id)->where('status', 'approved')->where('document_date', '>=', $from)
            ->whereNotIn('id', DB::table('purchase_payments')->select('purchase_document_id'))->orderBy('document_date')->orderBy('id')->first();
        if ($document !== null && ! DB::table('purchase_credit_notes')->where('purchase_document_id', $document->id)->exists()) {
            $issuedOn = $this->paidOn((string) $document->document_date, 3);
            $this->at(CarbonImmutable::parse($issuedOn)->setTime(10, 0), fn () => $this->act($this->owner, PurchaseController::class, 'creditNote', ['amount' => $this->decimal(intdiv((int) $document->total_cents, 20)),
                'issued_on' => $issuedOn, 'reason' => 'Bonificación del proveedor por mercadería entregada con daños', 'fiscal_series' => 'NC', 'fiscal_number' => (string) $document->fiscal_number],
                $company, ['document' => (string) $document->public_id]));
        }
    }

    /** Los asientos automáticos llegan como borradores; el propietario, único que contabiliza en el plan, los revisa y contabiliza. */
    private function postDrafts(stdClass $company): void
    {
        $accounting = app(AccountingService::class);
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $company->id)->where('e.status', 'draft')->orderBy('e.entry_date')->orderBy('e.id')->get(['e.*']);
        foreach ($entries as $entry) {
            if (in_array($entry->kind, ['standard', 'adjustment', 'opening'], true) && ! DB::table('journal_entry_documents')->where('journal_entry_id', $entry->id)->exists()) {
                $this->attachSupport($company, $entry);
            }
            $this->at(CarbonImmutable::parse((string) $entry->entry_date)->setTime(18, 30), fn () => $accounting->post((int) $company->id, $entry, $this->owner));
        }
    }

    /**
     * Soporte del asiento antes de contabilizarlo (por ejemplo, la orden de producción): un asiento contabilizado ya no
     * admite respaldos y, sin ellos, el cierre del período se bloquea.
     */
    private function attachSupport(stdClass $company, stdClass $entry): void
    {
        $file = UploadedFile::fake()->createWithContent('soporte-'.Str::slug((string) $entry->reference).'.pdf', $this->pdf(['Documento de soporte - demostracion',
            'Empresa: '.Str::ascii((string) $company->name).'  NIT '.$company->tax_id, 'Concepto: '.Str::ascii((string) $entry->description), 'Referencia: '.$entry->reference,
            'Fecha: '.$entry->entry_date]));
        $this->at(CarbonImmutable::parse((string) $entry->entry_date)->setTime(18, 0),
            fn () => $this->act($this->bookkeeperOf($company), AccountingDocumentController::class, 'store', [], $company, ['entry' => (string) $entry->public_id], ['document' => $file]));
    }

    /** Conciliación del banco al cierre del mes anterior: un cheque en circulación y la comisión sin registrar explican la diferencia. */
    private function reconcile(stdClass $company, stdClass $bank, CarbonImmutable $month): void
    {
        $asOf = $month->endOfMonth()->toDateString();
        if (DB::table('account_reconciliations')->where('company_id', $company->id)->where('ledger_account_id', $this->account('1102'))->where('as_of', $asOf)->exists()) {
            return;
        }
        $service = app(AccountReconciliationService::class);
        $preparer = $this->bookkeeper;
        $statement = (int) DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('transaction_date', '<=', $asOf)
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'inflow' THEN amount_cents ELSE -amount_cents END), 0) AS balance")->value('balance');
        $inTransit = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('transaction_date', '>', $asOf)->where('reference', 'like', 'CHQ-%')
            ->whereIn('reference', DB::table('purchase_payments as p')->join('purchase_documents as d', 'd.id', '=', 'p.purchase_document_id')
                ->where('d.company_id', $company->id)->where('p.paid_on', '<=', $asOf)->select('p.reference'))->get();
        $id = $service->start($this->organization, $preparer, (int) $company->id, ['ledger_account_id' => $this->account('1102'), 'as_of' => $asOf,
            'counterpart' => $bank->bank_name.' ••••'.$bank->last_four, 'supporting_balance' => $this->decimal($statement),
            'notes' => 'Saldo según estado de cuenta bancario al '.$month->endOfMonth()->format('d/m/Y').'.']);
        foreach ($inTransit as $check) {
            $service->addItem($this->organization, $preparer, (int) $company->id, $id, ['kind' => 'in_transit',
                'description' => 'Cheque '.$check->reference.' en circulación, cobrado el '.CarbonImmutable::parse((string) $check->transaction_date)->format('d/m/Y'),
                'amount' => '-'.$this->decimal((int) $check->amount_cents)]);
        }
        $service->addItem($this->organization, $preparer, (int) $company->id, $id, ['kind' => 'pending_adjustment',
            'description' => 'Comisión bancaria del mes pendiente de registrar', 'amount' => self::BANK_FEE]);
        $service->reconcile($this->organization, $preparer, (int) $company->id, $id);
        if ($preparer->id !== $this->owner->id) {
            $service->review($this->organization, $this->owner, (int) $company->id, $id);
        }
    }

    private function closeVatBook(stdClass $company, CarbonImmutable $month): void
    {
        $books = app(VatBookService::class);
        if ($books->book((int) $company->id, $month->year, $month->month)['status'] !== 'closed') {
            $books->close($this->organization, $this->owner, (int) $company->id, $month->year, $month->month);
        }
    }

    /** El propietario cierra el primer mes con el procedimiento real: checklist, fotografía contable y bloqueo del período. */
    private function closePeriod(stdClass $company, CarbonImmutable $month): void
    {
        $period = DB::table('accounting_periods')->where('company_id', $company->id)->where('starts_on', $month->toDateString())->first();
        if ($period === null || $period->closed_at !== null) {
            return;
        }
        $this->at($month->addMonths(2)->setDay(6)->setTime(18, 0), fn () => app(CloseAccountingPeriod::class)->execute($this->member, $company, (int) $period->id, $this->owner));
    }

    /**
     * Listado de DTE del mes anterior como lo descarga la oficina de la Agencia Virtual: las facturas emitidas y recibidas
     * registradas, más una factura recibida que la empresa aún no registró (el cruce la reporta como faltante).
     */
    private function satCrossCheck(stdClass $company, CarbonImmutable $month): void
    {
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $rows = [];
        foreach (DB::table('sales_invoices')->where('company_id', $company->id)->where('status', 'issued')->whereBetween('issued_on', [$from, $to])->whereNotNull('fiscal_authorization')->get() as $sale) {
            $rows[] = $this->dteRow((string) $sale->fiscal_authorization, $sale->fiscal_series, $sale->fiscal_number, (string) $sale->issued_on, (string) $company->tax_id, (string) $company->name,
                (string) $sale->customer_tax_id, (string) $sale->customer_name, (int) $sale->total_cents, (int) ($sale->vat_cents ?? 0));
        }
        foreach (DB::table('purchase_documents')->where('company_id', $company->id)->where('status', 'approved')->whereBetween('document_date', [$from, $to])->whereNotNull('fiscal_authorization')->get() as $purchase) {
            $rows[] = $this->dteRow((string) $purchase->fiscal_authorization, $purchase->fiscal_series, $purchase->fiscal_number, (string) $purchase->document_date, (string) $purchase->supplier_tax_id,
                (string) $purchase->supplier_name, (string) $company->tax_id, (string) $company->name, (int) $purchase->total_cents, (int) ($purchase->vat_cents ?? 0));
        }
        $missing = PlanEsencialDemoCatalog::missingSatInvoice();
        $rows[] = $this->dteRow($this->authorization($company, 'sat-missing:'.$month->format('Ym')), $missing['series'], $missing['number'], $month->setDay(21)->toDateString(),
            $missing['tax_id'], $missing['name'], (string) $company->tax_id, (string) $company->name, $missing['total_cents'], $missing['vat_cents']);
        app(SatDteImportService::class)->import($this->organization, $this->owner, $company, $rows);
    }

    /**
     * @return array{authorization: string, document_type: string, series: string|null, number: string|null, issued_on: string, issuer_tax_id: string|null, issuer_name: string|null, receiver_tax_id: string|null, receiver_name: string|null, currency: string, total_cents: int, vat_cents: int|null, status: string, annulled_on: string|null}
     */
    private function dteRow(string $authorization, mixed $series, mixed $number, string $issuedOn, string $issuerTaxId, string $issuerName, string $receiverTaxId, string $receiverName, int $total, int $vat): array
    {
        return ['authorization' => Str::upper($authorization), 'document_type' => 'FACT', 'series' => $series === null ? null : (string) $series, 'number' => $number === null ? null : (string) $number,
            'issued_on' => substr($issuedOn, 0, 10), 'issuer_tax_id' => $issuerTaxId, 'issuer_name' => $issuerName, 'receiver_tax_id' => $receiverTaxId, 'receiver_name' => $receiverName,
            'currency' => 'GTQ', 'total_cents' => $total, 'vat_cents' => $vat, 'status' => 'valid', 'annulled_on' => null];
    }

    /**
     * Partidas del mes en curso en cada estado del flujo de revisión. La contadora prepara y el propietario revisa,
     * aprueba, rechaza o contabiliza; un ajuste nunca lo aprueba quien lo creó. En una empresa del propietario se corrige
     * un asiento contabilizado con su reverso.
     */
    private function journalWorkflow(stdClass $company, int $index, CarbonImmutable $month): void
    {
        $this->accounts = DB::table('ledger_accounts')->where('company_id', $company->id)->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        $accounting = app(AccountingService::class);
        $preparer = $this->bookkeeperOf($company);
        $line = fn (string $code, string $debit, string $credit): array => ['account_id' => $this->account($code), 'debit' => $debit, 'credit' => $credit];

        if ($preparer->id !== $this->owner->id && $index < 3) {
            if (($date = $this->day($month, 3)) !== null) {
                $entry = $this->manualEntry($company, $preparer, 'flow:provision', $date, 'provision', 'Provisión de honorarios de auditoría externa del mes', 'PROV-'.$month->format('Ym'),
                    [$line('5208', '1850.00', '0'), $line('2102', '0', '1850.00')], false);
                if ($entry !== null && $entry->status === 'draft') {
                    $accounting->submitForReview($this->organization, $entry, $preparer);
                }
            }
            if (($date = $this->day($month, 4)) !== null) {
                $entry = $this->manualEntry($company, $preparer, 'flow:adjustment', $date, 'adjustment', 'Ajuste por faltante en arqueo de caja chica', 'ARQ-'.$month->format('Ym'),
                    [$line('5201', '35.50', '0'), $line('1101', '0', '35.50')]);
                if ($entry !== null && $entry->status === 'draft') {
                    $accounting->submitForReview($this->organization, $entry, $preparer);
                    $accounting->approve($this->organization, $this->refresh($entry), $this->owner);
                    $accounting->post((int) $company->id, $this->refresh($entry), $this->owner);
                }
            }
            if (($date = $this->day($month, 5)) !== null) {
                $entry = $this->manualEntry($company, $preparer, 'flow:reclassification', $date, 'reclassification', 'Reclasificación de papelería registrada como servicios básicos', 'RCL-'.$month->format('Ym'),
                    [$line('5201', '120.00', '0'), $line('5204', '0', '120.00')], false);
                if ($entry !== null && $entry->status === 'draft') {
                    $accounting->submitForReview($this->organization, $entry, $preparer);
                    $accounting->review($this->organization, $this->refresh($entry), $this->owner);
                }
            }
            if (($date = $this->day($month, 6)) !== null) {
                $entry = $this->manualEntry($company, $preparer, 'flow:rejected', $date, 'standard', 'Pago de telefonía del mes', 'TEL-'.$month->format('Ym'),
                    [$line('5204', '448.00', '0'), $line('1102', '0', '448.00')], false);
                if ($entry !== null && $entry->status === 'draft') {
                    $accounting->submitForReview($this->organization, $entry, $preparer);
                    $accounting->reject($this->organization, $this->refresh($entry), $this->owner, 'Registro duplicado: el pago ya está contabilizado desde la compra al contado del proveedor.');
                }
            }
            if (($date = $this->day($month, 7)) !== null) {
                $this->manualEntry($company, $preparer, 'flow:draft', $date, 'standard', 'Estimación de intereses y cargos bancarios del mes', 'INT-'.$month->format('Ym'),
                    [$line('5209', '85.00', '0'), $line('1102', '0', '85.00')], false);
            }
        }

        if ($preparer->id === $this->owner->id && ($date = $this->day($month, 3)) !== null) {
            $wrong = $this->manualEntry($company, $this->owner, 'flow:wrong-account', $date, 'standard', 'Pago de servicio de internet del mes', 'NET-'.$month->format('Ym'),
                [$line('5208', '560.00', '0'), $line('1102', '0', '560.00')]);
            if ($wrong !== null && $wrong->status === 'draft') {
                $accounting->post((int) $company->id, $wrong, $this->owner);
                $reversalOn = $this->paidOn($date, 1);
                $accounting->reverse($this->organization, $this->owner, (int) $company->id, $this->refresh($wrong), (int) $wrong->accounting_period_id, $reversalOn,
                    'El servicio de internet se registró en honorarios profesionales; corresponde a servicios básicos.');
                $right = $this->manualEntry($company, $this->owner, 'flow:right-account', $reversalOn, 'standard', 'Pago de servicio de internet del mes (corrección)', 'NET-'.$month->format('Ym'),
                    [$line('5204', '560.00', '0'), $line('1102', '0', '560.00')]);
                if ($right !== null && $right->status === 'draft') {
                    $accounting->post((int) $company->id, $right, $this->owner);
                }
            }
        }
    }

    private function refresh(stdClass $entry): stdClass
    {
        return DB::table('journal_entries')->where('id', $entry->id)->firstOrFail();
    }

    /** Cotizaciones y órdenes del mes en curso en sus distintos estados; una cotización aceptada se convierte en factura en borrador. */
    private function commercialDocuments(stdClass $company, int $index, CarbonImmutable $month): void
    {
        if ($index > 2 || ($date = $this->day($month, 2)) === null) {
            return;
        }
        $service = app(CommercialDocumentService::class);
        $customers = DB::table('sales_customers')->where('company_id', $company->id)->where('tax_id', '<>', 'CF')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $supplier = (int) DB::table('purchase_suppliers')->where('company_id', $company->id)->orderBy('id')->value('id');
        $purchaseAccount = (int) DB::table('ledger_accounts')->where('company_id', $company->id)->where('code', '5103')->value('id');
        if (count($customers) < 2 || $supplier === 0 || $purchaseAccount === 0) {
            return;
        }
        $validUntil = CarbonImmutable::parse($date)->addDays(15)->toDateString();
        $definitions = [
            ['quote', $customers[0], 'Cotización de pedido de temporada', ['sent', 'accepted'], true, [['Pedido de temporada según lista de precios', 10, '560.00', 'goods']]],
            ['quote', $customers[1], 'Cotización solicitada por correo, pendiente de respuesta', ['sent'], false, [['Suministro mensual según requerimiento', 4, '1120.00', 'goods']]],
            ['quote', $customers[0], 'Cotización de servicio adicional no aceptada por el cliente', ['sent', 'rejected'], false, [['Servicio adicional de entrega a domicilio', 1, '850.00', 'services']]],
            ['sales_order', $customers[1], 'Orden de venta confirmada por teléfono', [], false, [['Despacho programado para la próxima semana', 6, '475.00', 'goods']]],
            ['purchase_order', $supplier, 'Orden de compra para reabastecer bodega', ['sent'], false, [['Reabastecimiento de mercadería', 1, '4480.00', 'goods']]],
        ];
        foreach ($definitions as [$kind, $counterparty, $notes, $steps, $convert, $lines]) {
            if (DB::table('commercial_documents')->where('company_id', $company->id)->where('kind', $kind)->where('notes', $notes)->exists()) {
                continue;
            }
            $publicId = '';
            $this->at(CarbonImmutable::parse($date)->setTime(9, 0), function () use ($service, $company, $kind, $counterparty, $notes, $steps, $lines, $date, $validUntil, $purchaseAccount, &$publicId): void {
                $id = $service->create($this->organization, $this->bookkeeperOf($company), (int) $company->id, ['kind' => $kind, 'counterparty_id' => $counterparty, 'issued_on' => $date,
                    'valid_until' => $kind === 'quote' ? $validUntil : null, 'notes' => $notes,
                    'lines' => array_map(fn (array $line): array => ['description' => $line[0], 'quantity' => $line[1], 'unit_price' => $line[2], 'tax_treatment' => $line[3],
                        'account_id' => $kind === 'purchase_order' ? $purchaseAccount : null], $lines)]);
                $publicId = (string) DB::table('commercial_documents')->where('id', $id)->value('public_id');
                foreach ($steps as $status) {
                    $service->transition($this->organization, $this->owner, (int) $company->id, $publicId, $status);
                }
            });
            if ($convert) {
                $this->at(CarbonImmutable::parse($date)->setTime(9, 30), fn () => $service->convert($this->organization, $this->owner, $company, $publicId,
                    ['target' => 'sales_invoice', 'document_date' => $date, 'due_on' => null]));
            }
        }
    }

    /**
     * Calendario fiscal real de cada empresa. Lo de meses cerrados se prepara, revisa y presenta con su constancia, salvo:
     * el IVA del mes anterior de dos empresas de la contadora (en revisión) y de una del propietario (en proceso), y el IVA
     * del primer mes de una empresa que quedó vencido.
     *
     * @param  list<array{0: stdClass, 1: DemoProfile}>  $companies
     * @param  list<CarbonImmutable>  $months
     */
    private function obligations(array $companies, array $months): void
    {
        $ids = array_map(fn (array $pair): int => (int) $pair[0]->id, $companies);
        app(FiscalObligationGenerationService::class)->generate($this->member, $this->owner, $ids);
        $holds = [];
        foreach ($companies as $index => [$company, $profile]) {
            $hold = match (true) {
                ! $profile['owner_books'] && $index < 2 => [$months[1], 'review'],
                $profile['owner_books'] && $index === 6 => [$months[1], 'progress'],
                $index === count($companies) - 1 => [$months[0], 'overdue'],
                default => null,
            };
            if ($hold !== null) {
                $id = DB::table('fiscal_obligations')->where('company_id', $company->id)->where('obligation_type', 'iva')
                    ->where('reference_year', $hold[0]->year)->where('reference_month', $hold[0]->month)->value('id');
                if ($id !== null) {
                    $holds[(int) $id] = $hold[1];
                }
            }
        }

        $workflow = app(FiscalObligationWorkflowService::class);
        $policy = app(ReviewPolicyService::class)->policy($this->organization);
        $obligations = DB::table('fiscal_obligations')->whereIn('company_id', $ids)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable'])->orderBy('due_on')->orderBy('id')->get();
        foreach ($obligations as $obligation) {
            if (CarbonImmutable::create((int) $obligation->reference_year, (int) $obligation->reference_month, 1)->greaterThanOrEqualTo($months[2])) {
                continue;
            }
            $company = DB::table('companies')->where('id', $obligation->company_id)->firstOrFail();
            $preparer = $this->bookkeeperOf($company);
            $id = (int) $obligation->id;
            if ($obligation->status === 'pending' && (int) $obligation->assigned_to !== $preparer->id) {
                DB::table('fiscal_obligations')->where('id', $id)->update(['assigned_to' => $preparer->id,
                    'reviewer_id' => $preparer->id === $this->owner->id ? null : $this->owner->id, 'updated_at' => now()]);
            }
            $hold = $holds[$id] ?? null;
            if ($hold === 'overdue') {
                continue;
            }
            $filedOn = CarbonImmutable::parse((string) $obligation->due_on)->subDays(3)->min(CarbonImmutable::today());
            $this->attempt($company, 'la obligación '.$obligation->name.' · '.$obligation->reference_period, function () use ($workflow, $policy, $obligation, $preparer, $filedOn, $company, $id, $hold): void {
                $status = (string) $obligation->status;
                $this->at($filedOn->subDays(4)->setTime(9, 15), function () use ($workflow, $preparer, $id, &$status): void {
                    if (in_array($status, ['pending', 'changes_requested'], true)) {
                        $workflow->start($this->organization, $preparer, $id);
                        $status = 'in_progress';
                    }
                });
                if ($hold === 'progress') {
                    return;
                }
                $this->at($filedOn->subDays(2)->setTime(16, 40), function () use ($workflow, $policy, $preparer, $id, &$status): void {
                    if ($status === 'in_progress' && $preparer->id === $this->owner->id) {
                        $workflow->finalizeOwn($this->organization, $preparer, $id, $policy);
                        $status = 'approved';
                    } elseif ($status === 'in_progress') {
                        $workflow->submit($this->organization, $preparer, $id);
                        $status = 'ready_for_review';
                    }
                });
                if ($hold === 'review') {
                    return;
                }
                $this->at($filedOn->subDay()->setTime(10, 30), function () use ($workflow, $id, &$status): void {
                    if ($status === 'ready_for_review') {
                        $workflow->approve($this->organization, $this->owner, $id, 'Revisada contra libros de IVA y conciliación bancaria.');
                        $status = 'approved';
                    }
                });
                $this->at($filedOn->setTime(11, 5), function () use ($workflow, $obligation, $preparer, $id, $filedOn, $company): void {
                    $reference = sprintf('%d%09d', $filedOn->year, crc32($company->id.':'.$obligation->generation_key) % 1000000000);
                    $receipt = UploadedFile::fake()->createWithContent('constancia-sat-'.$reference.'.pdf', $this->pdf(['Constancia de presentacion - documento de demostracion',
                        'Contribuyente: '.Str::ascii((string) $company->name).'  NIT '.$company->tax_id, 'Formulario: '.Str::ascii($obligation->name.' - '.$obligation->reference_period),
                        'Numero de acceso: '.$reference, 'Fecha de presentacion: '.$filedOn->format('d/m/Y')]));
                    $workflow->complete($this->organization, $preparer, $id, $filedOn->toDateString(), $reference, $receipt, 'Presentada en Declaraguate (demostración).');
                });
            });
        }
    }

    /**
     * Tareas operativas de cada empresa en sus distintos estados: completada, en proceso, en revisión, pendiente y vencida.
     * Las asigna el propietario a quien lleva los libros; las de la contadora las revisa el propietario.
     *
     * @param  list<array{0: stdClass, 1: DemoProfile}>  $companies
     * @param  list<CarbonImmutable>  $months
     */
    private function tasks(array $companies, array $months): void
    {
        $service = app(WorkTaskService::class);
        $label = fn (CarbonImmutable $month): string => mb_strtolower(SpanishMonth::label($month));
        foreach ($companies as [$company]) {
            $assignee = $this->bookkeeperOf($company);
            $reviewer = $assignee->id === $this->owner->id ? null : $this->owner;
            $definitions = [
                ['bank', 'banking', 'bank_reconciliation', 'Conciliar el estado de cuenta bancario de '.$label($months[1]), 'completed', -3, 'high'],
                ['sat', 'tax', 'sat_cross_check', 'Registrar las compras faltantes del cruce con la SAT de '.$label($months[1]), 'in_progress', 4, 'high'],
                ['receivables', 'receivables', 'receivables_review', 'Revisar la antigüedad de saldos de clientes', $reviewer === null ? 'in_progress' : 'in_review', 2, 'normal'],
                ['client', 'client', 'client_documents', 'Solicitar al cliente los estados de cuenta y facturas de '.$label($months[2]), 'pending', 6, 'normal'],
                ['archive', 'documents', 'archive_documents', 'Archivar las facturas físicas de '.$label($months[0]), 'pending', -4, 'low'],
            ];
            foreach ($definitions as [$key, $module, $type, $title, $target, $days, $priority]) {
                $this->attempt($company, 'la tarea '.$title, function () use ($service, $company, $assignee, $reviewer, $key, $module, $type, $title, $target, $days, $priority): void {
                    $id = $service->create($this->member, $this->owner, ['office_id' => (int) $company->office_id, 'company_id' => (int) $company->id, 'module' => $module, 'task_type' => $type,
                        'title' => $title, 'assigned_to' => $assignee->id, 'reviewer_id' => $reviewer?->id, 'priority' => $priority, 'due_at' => now()->addDays($days)->setTime(17, 0),
                        'automation_key' => 'plan-esencial-demo:'.$company->id.':'.$key]);
                    if (DB::table('work_tasks')->where('id', $id)->value('status') !== 'pending' || $target === 'pending') {
                        return;
                    }
                    $service->transition($this->member, $assignee, $id, 'in_progress', 'Iniciada: se reunió la documentación del cliente.');
                    if ($target === 'in_progress') {
                        return;
                    }
                    if ($reviewer === null) {
                        $service->finishOwn($this->member, $assignee, $id, 'Terminada y verificada contra los registros.');

                        return;
                    }
                    $service->transition($this->member, $assignee, $id, 'ready_for_review', 'Lista para revisión del propietario.');
                    $service->transition($this->member, $reviewer, $id, 'in_review');
                    if ($target === 'in_review') {
                        return;
                    }
                    $service->transition($this->member, $reviewer, $id, 'approved', 'Revisada sin observaciones.');
                    $service->transition($this->member, $reviewer, $id, 'completed');
                });
            }
        }
    }

    /**
     * Crea un asiento manual en borrador y, cuando se pide, le adjunta su documento de soporte (los asientos manuales
     * contabilizados sin soporte bloquean el cierre del período).
     *
     * @param  list<array{account_id: int, debit: string, credit: string}>  $lines
     */
    private function manualEntry(stdClass $company, User $author, string $key, string $date, string $kind, string $description, string $reference, array $lines, bool $support = true): ?stdClass
    {
        $requestId = $this->uuid($company, $key);
        $existing = DB::table('journal_entries')->where('public_id', $requestId)->first();
        if ($existing !== null) {
            return $existing;
        }
        $periodId = (int) DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->value('id');
        if ($periodId === 0) {
            return null;
        }
        $accounting = app(AccountingService::class);
        $this->at(CarbonImmutable::parse($date)->setTime(9, 45), fn () => $accounting->createDraft($this->organization, $author, $company, ['request_id' => $requestId,
            'period_id' => $periodId, 'entry_date' => $date, 'description' => $description, 'reference' => $reference, 'kind' => $kind], $accounting->lines((int) $company->id, $lines)));
        if ($support) {
            $file = UploadedFile::fake()->createWithContent('soporte-'.Str::slug($reference).'.pdf', $this->pdf(['Documento de soporte - demostracion',
                'Empresa: '.Str::ascii((string) $company->name).'  NIT '.$company->tax_id, 'Concepto: '.Str::ascii($description), 'Referencia: '.$reference, 'Fecha: '.$date]));
            $this->act($this->owner, AccountingDocumentController::class, 'store', [], $company, ['entry' => $requestId], ['document' => $file]);
        }

        return DB::table('journal_entries')->where('public_id', $requestId)->first();
    }

    /**
     * Ejecuta la acción de un controlador como el usuario indicado, igual que el formulario de la pantalla.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $parameters
     * @param  array<string, UploadedFile>  $files
     */
    private function act(User $user, string $controller, string $action, array $input, stdClass $company, array $parameters = [], array $files = []): void
    {
        $request = Request::create('/', 'POST', $input, [], $files);
        $request->setUserResolver(fn (): User => $user);
        Auth::setUser($user);
        app()->instance('request', $request);
        app()->call(app($controller)->{$action}(...), ['request' => $request, 'company' => (string) $company->public_id, ...$parameters]);
    }

    /** Ejecuta el paso como si ocurriera en esa fecha (nunca en el futuro), para que el historial tenga fechas reales. */
    private function at(CarbonImmutable $moment, Closure $callback): void
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment->isAfter(Carbon::now()) ? Carbon::now() : $moment);
        try {
            $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * Cada paso corre en su propia transacción: si una regla real del sistema lo rechaza, se deshace completo y se informa.
     *
     * @param  Closure(): void  $callback
     */
    private function attempt(stdClass $company, string $step, Closure $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (ValidationException $exception) {
            $this->warn("{$company->name}: no se completó {$step}. ".collect($exception->errors())->flatten()->implode(' '));
        } catch (HttpExceptionInterface $exception) {
            $this->warn("{$company->name}: no se completó {$step}. ".($exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP '.$exception->getStatusCode().' en '.basename($exception->getFile()).':'.$exception->getLine()));
        }
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->command->warn($message);
    }

    /** @param list<int> $companyIds */
    private function report(array $companyIds): void
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->whereIn('p.company_id', $ids);
        $unbalanced = DB::query()->fromSub(DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->whereIn('p.company_id', $ids)->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->select('e.id'), 'unbalanced')->count();
        $rows = [
            ['Organización', PlanEsencialDemoCatalog::firm()['name'].' (NIT '.PlanEsencialDemoCatalog::ORGANIZATION_TAX_ID.')'],
            ['Usuarios de oficina', (string) DB::table('organization_user')->where('organization_id', $this->organization->id)->where('status', 'active')->count()],
            ['Oficinas', (string) DB::table('offices')->where('organization_id', $this->organization->id)->whereNull('deleted_at')->count()],
            ['Empresas cliente', (string) count($companyIds)],
            ['Clientes de las empresas', (string) DB::table('sales_customers')->whereIn('company_id', $ids)->count()],
            ['Proveedores', (string) DB::table('purchase_suppliers')->whereIn('company_id', $ids)->count()],
            ['Facturas de venta', (string) DB::table('sales_invoices')->whereIn('company_id', $ids)->count()],
            ['Recibos de cobro', (string) DB::table('sales_receipts as r')->join('sales_invoices as i', 'i.id', '=', 'r.sales_invoice_id')->whereIn('i.company_id', $ids)->count()],
            ['Notas de crédito (ventas / compras)', DB::table('sales_credit_notes')->whereIn('company_id', $ids)->count().' / '.DB::table('purchase_credit_notes')->whereIn('company_id', $ids)->count()],
            ['Documentos de compra', (string) DB::table('purchase_documents')->whereIn('company_id', $ids)->count()],
            ['Pagos a proveedores', (string) DB::table('purchase_payments as p')->join('purchase_documents as d', 'd.id', '=', 'p.purchase_document_id')->whereIn('d.company_id', $ids)->count()],
            ['Cotizaciones y órdenes', (string) DB::table('commercial_documents')->whereIn('company_id', $ids)->count()],
            ['Asientos contables', (string) (clone $entries)->count()],
            ['Asientos por estado', (clone $entries)->groupBy('e.status')->orderBy('e.status')->selectRaw('e.status, COUNT(*) as total')->get()->map(fn (stdClass $row): string => $row->status.': '.$row->total)->implode(', ')],
            ['Asientos descuadrados', (string) $unbalanced],
            ['Movimientos bancarios', (string) DB::table('bank_transactions as t')->join('bank_accounts as a', 'a.id', '=', 't.bank_account_id')->whereIn('a.company_id', $ids)->count()],
            ['Conciliaciones bancarias', (string) DB::table('account_reconciliations')->whereIn('company_id', $ids)->count()],
            ['Períodos (abiertos / cerrados)', DB::table('accounting_periods')->whereIn('company_id', $ids)->whereNull('closed_at')->count().' / '.DB::table('accounting_periods')->whereIn('company_id', $ids)->whereNotNull('closed_at')->count()],
            ['Libros de IVA cerrados', (string) DB::table('vat_book_periods')->whereIn('company_id', $ids)->where('status', 'closed')->count()],
            ['DTE del cruce con la SAT', (string) DB::table('sat_dte_records')->whereIn('company_id', $ids)->count()],
            ['Obligaciones fiscales', DB::table('fiscal_obligations')->whereIn('company_id', $ids)->groupBy('status')->orderBy('status')->selectRaw('status, COUNT(*) as total')->get()->map(fn (stdClass $row): string => $row->status.': '.$row->total)->implode(', ')],
            ['Tareas', DB::table('work_tasks')->whereIn('company_id', $ids)->whereNull('deleted_at')->groupBy('status')->orderBy('status')->selectRaw('status, COUNT(*) as total')->get()->map(fn (stdClass $row): string => $row->status.': '.$row->total)->implode(', ')],
            ['Pasos no completados', (string) count($this->warnings)],
        ];
        $this->command->table(['Registro', 'Cantidad'], $rows);
        $this->command->info('Propietario: '.PlanEsencialDemoCatalog::OWNER_EMAIL.' · Contadora encargada: '.PlanEsencialDemoCatalog::COLLABORATOR_EMAIL.' · Cada usuario tiene su propia contraseña aleatoria.');
        if ($this->credentials !== []) {
            $this->command->warn('Credenciales de los usuarios creados ahora. Anótalas: no se guardan en claro y no se vuelven a mostrar.');
            $this->command->table(['Correo', 'Nombre', 'Contraseña'], $this->credentials);
        } else {
            $this->command->info('No se crearon usuarios nuevos: los existentes conservan su contraseña.');
        }
    }

    /** @param list<string> $lines Un PDF de una página que hace las veces del documento físico de soporte. */
    private function pdf(array $lines): string
    {
        $text = 'BT /F1 12 Tf 60 760 Td 18 TL';
        foreach ($lines as $line) {
            $text .= ' ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).') Tj T*';
        }
        $text .= ' ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n".$text."\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%EOF\n";
    }

    private function account(string $code): int
    {
        return $this->accounts[$code] ?? throw new RuntimeException("Falta la cuenta contable {$code} en el catálogo de la empresa.");
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function uuid(stdClass $company, string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.plan-esencial-demo.'.$company->id.'.'.$key)->toString();
    }

    /** Número de autorización ficticio con formato de UUID: no corresponde a ningún DTE certificado. */
    private function authorization(stdClass $company, string $key): string
    {
        return Str::upper(Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.plan-esencial-demo.dte.'.$company->id.'.'.$key)->toString());
    }
}
