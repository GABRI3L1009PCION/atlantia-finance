<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Http\Controllers\BankController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SalesController;
use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\AccountReconciliationService;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Fiscal\VatBookService;
use App\Services\WorkManagement\CompanyAdministrationService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;

/**
 * Three months of bookkeeping for the Esencial demo clients: customers, suppliers, sales, purchases, collections, payments,
 * the bank statement, its reconciliation and the closed VAT books. Every document goes through the same controller
 * actions the screens use, so the automatic entries, VAT and balances are exactly what a real user would get.
 * Names, NITs and amounts are fictitious. Running it again only adds what is missing.
 */
class EssentialPlanAccountingDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const BANK_FEE = '35.00';

    private Organization $organization;

    private User $owner;

    /** The person who keeps the books of the company being seeded: its accountant in charge, or the owner. */
    private User $bookkeeper;

    /** @var array<int|string, int> Ledger account ids by code. */
    private array $accounts = [];

    /** @var (Closure(string): void)|null */
    private ?Closure $warn = null;

    public function run(): void
    {
        $this->refuseInProduction();

        $organization = Organization::query()->where('country_code', 'GT')->where('tax_identifier', '3344556-7')->first();
        $owner = $organization === null ? null : $this->ownerOf($organization);
        if ($organization === null || $owner === null) {
            $this->command->warn('Ejecuta EssentialPlanDemoSeeder antes de EssentialPlanAccountingDemoSeeder.');

            return;
        }

        $this->seedOrganization($organization, $owner, 4, fn (string $message) => $this->command->warn($message));
    }

    /**
     * Books three months for the organization's clients, creating demo clients until it has $clients of them.
     * Existing clients keep their data: the demo only adds its own documents next to it.
     *
     * @param  (Closure(string): void)|null  $warn
     * @return list<string> The clients that received the demo books.
     */
    public function seedOrganization(Organization $organization, User $owner, int $clients, ?Closure $warn = null): array
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->warn = $warn;
        $this->ensureClients($clients);

        $profiles = $this->profiles();
        $companies = $this->activeCompanies()->orderBy('id')->limit($clients)->get();
        $byTaxId = array_column($profiles, null, 'tax_id');
        $unused = array_values(array_filter($profiles, fn (array $profile): bool => ! $companies->contains('tax_id', $profile['tax_id'])));
        $seeded = [];
        foreach ($companies as $company) {
            $profile = $byTaxId[$company->tax_id] ?? $this->takeProfileFor((string) $company->name, $unused);
            if ($profile !== null) {
                $this->company($company, $profile);
                $seeded[] = (string) $company->name;
            }
        }

        return $seeded;
    }

    /**
     * The unused profile whose line of business matches the client's name, or the first one left.
     *
     * @param  list<array<string, mixed>>  $unused
     * @return array<string, mixed>|null
     */
    private function takeProfileFor(string $name, array &$unused): ?array
    {
        $name = mb_strtolower($name);
        foreach ($unused as $index => $profile) {
            foreach ($profile['keywords'] ?? [] as $keyword) {
                if (str_contains($name, $keyword)) {
                    array_splice($unused, $index, 1);

                    return $profile;
                }
            }
        }

        return array_shift($unused);
    }

    /** Clients of the organization that are not in the trash. */
    private function activeCompanies(): Builder
    {
        return DB::table('companies')->where('organization_id', $this->organization->id)
            ->whereNotIn('id', DB::table('company_deletions')->whereNull('restored_at')->select('company_id'));
    }

    public function ownerOf(Organization $organization): ?User
    {
        $id = DB::table('organization_user')->where('organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->orderBy('user_id')->value('user_id');

        return $id === null ? null : User::query()->whereKey((int) $id)->first();
    }

    /** Creates demo clients, through the same service as the Clients screen, until the organization has as many as asked. */
    private function ensureClients(int $wanted): void
    {
        $existing = $this->activeCompanies()->pluck('tax_id')->all();
        $missing = $wanted - count($existing);
        $officeId = (int) DB::table('offices')->where('organization_id', $this->organization->id)->where('status', 'active')->orderByDesc('is_primary')->orderBy('id')->value('id');
        $member = DB::table('organization_user')->where('organization_id', $this->organization->id)
            ->where('status', 'active')->where('user_id', '<>', $this->owner->id)->orderBy('user_id')->value('user_id');
        $ownerInOrganization = $this->owner->organizations()->whereKey($this->organization->id)->firstOrFail();
        foreach ($this->profiles() as $profile) {
            if ($missing <= 0 || $officeId === 0) {
                break;
            }
            if (in_array($profile['tax_id'], $existing, true)) {
                continue;
            }
            $company = app(CompanyAdministrationService::class)->create($ownerInOrganization, $this->owner, ['name' => $profile['name'], 'tax_id' => $profile['tax_id'],
                'office_id' => $officeId, 'accountant_id' => $member === null ? null : (int) $member]);
            DB::table('companies')->where('id', $company['id'])->update(['created_at' => now()->subMonths(6)]);
            $missing--;
        }
    }

    /** @param array<string, mixed> $profile */
    private function company(stdClass $company, array $profile): void
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

        $months = $this->months();
        $this->openPeriods($company, $months);
        $this->openingCapital($company, $months[0], $profile['capital']);
        $customers = $this->customers($company, $profile['customers']);
        $suppliers = $this->suppliers($company, $profile['suppliers']);
        $bank = $this->bankAccount($company, $profile['bank']);

        foreach ($months as $index => $month) {
            $last = $index === count($months) - 1;
            foreach ($profile['sales'] as $number => [$customer, $day, $lines, $collected]) {
                $date = $this->day($month, $day);
                if ($date === null) {
                    continue;
                }
                $reference = sprintf('%s-%d', $month->format('Ym'), $number + 1);
                $invoice = $this->sale($company, $customers[$customer], $date, $lines, $reference, $profile['series']);
                // Older months are collected; the current one keeps balances open so receivables and reminders have data.
                $share = $last ? $collected * 0.5 : ($index === 0 && $number === 0 ? 0.0 : $collected);
                if ($share > 0) {
                    $this->collect($company, $bank, $invoice, $this->paidOn($date, 6), $share);
                }
            }
            foreach ($profile['purchases'] as $number => [$supplier, $day, $account, $treatment, $description, $amount]) {
                $date = $this->day($month, $day);
                if ($date === null) {
                    continue;
                }
                $document = $this->purchase($company, $suppliers[$supplier], $date, $account, $treatment, $description, $amount,
                    sprintf('%s%02d', $month->format('ym'), $number + 1));
                if (! $last || $day <= 10) {
                    // The last payment of the month before the current one is a check the bank has not cashed yet.
                    $cashed = ! ($index === count($months) - 2 && $number === count($profile['purchases']) - 1);
                    $this->pay($company, $bank, $document, $this->paidOn($date, 8), $cashed);
                }
            }
            if (! $last) {
                $this->bankFee($company, $bank, $month, $index === 0);
            }
        }

        $this->postDrafts($company);
        // A client with its own earlier movements may not reconcile or close with the demo figures alone: that is reported, not forced.
        $this->attempt($company, 'la conciliación bancaria', fn () => $this->reconcile($company, $bank, $months[count($months) - 2]));
        foreach (array_slice($months, 0, -1) as $month) {
            $this->attempt($company, 'el libro de IVA de '.$month->format('m/Y'), fn () => $this->closeVatBook($company, $month));
        }
    }

    /** The accountant in charge of the client when it has one; otherwise the owner keeps its books. */
    private function bookkeeperOf(stdClass $company): User
    {
        $id = DB::table('company_assignments')->where('company_id', $company->id)->where('assignment_role', 'accountant_in_charge')->where('status', 'active')
            ->whereNull('deleted_at')->where('user_id', '<>', $this->owner->id)->orderByDesc('is_primary')->value('user_id');

        return ($id === null ? null : User::query()->whereKey((int) $id)->first()) ?? $this->owner;
    }

    /** @param Closure(): void $callback */
    private function attempt(stdClass $company, string $step, Closure $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (ValidationException $exception) {
            if ($this->warn !== null) {
                ($this->warn)("{$company->name}: no se completó {$step}. ".collect($exception->errors())->flatten()->implode(' '));
            }
        }
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

    /** @return list<CarbonImmutable> The two closed months and the current one. */
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

    private function openingCapital(stdClass $company, CarbonImmutable $month, string $amount): void
    {
        $this->manualEntry($company, 'capital', $month->toDateString(), 'opening', 'Aporte inicial de capital depositado en banco', 'APERTURA',
            [['account_id' => $this->account('1102'), 'debit' => $amount, 'credit' => '0'], ['account_id' => $this->account('3101'), 'debit' => '0', 'credit' => $amount]]);
    }

    /**
     * @param  list<array{string, string, string, int}>  $rows
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
     * @param  list<array{string, string, string, string, int}>  $rows
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

    /** @param array{string, string, string} $bank */
    private function bankAccount(stdClass $company, array $bank): stdClass
    {
        [$bankName, $accountName, $lastFour] = $bank;
        $account = DB::table('bank_accounts')->where('company_id', $company->id)->where('last_four', $lastFour)->first();
        if ($account === null) {
            $this->act($this->owner, BankController::class, 'storeAccount', ['ledger_account_id' => $this->account('1102'), 'bank_name' => $bankName,
                'account_name' => $accountName, 'account_type' => 'checking', 'last_four' => $lastFour, 'bank_balance' => '0.00', 'currency' => 'GTQ'], $company);
            $account = DB::table('bank_accounts')->where('company_id', $company->id)->where('last_four', $lastFour)->first();
            $capital = DB::table('journal_entries')->where('public_id', $this->uuid($company, 'capital'))->first();
            $this->bankMovement($company, $account, (string) $capital->entry_date, 'DEP-APERTURA', 'Depósito del aporte inicial de capital', 'inflow',
                $this->decimal((int) DB::table('journal_lines')->where('journal_entry_id', $capital->id)->sum('debit_cents')));
        }

        return $account;
    }

    /** @param list<array{string, int, string, string}> $lines */
    private function sale(stdClass $company, int $customerId, string $date, array $lines, string $reference, string $series): stdClass
    {
        $requestId = $this->uuid($company, 'sale:'.$reference);
        $this->act($this->bookkeeper, SalesController::class, 'store', ['request_id' => $requestId, 'customer_id' => $customerId, 'issued_on' => $date,
            'fiscal_document_type' => 'FACT', 'fiscal_series' => $series, 'fiscal_number' => str_replace('-', '', $reference),
            'fiscal_authorization' => strtoupper(substr(str_replace('-', '', $requestId), 0, 32)),
            'lines' => array_map(fn (array $line): array => ['description' => $line[0], 'quantity' => $line[1], 'unit_price' => $line[2], 'tax_treatment' => $line[3]], $lines)], $company);
        $invoice = DB::table('sales_invoices')->where('public_id', $requestId)->first();
        if ($invoice->status === 'draft') {
            $this->act($this->owner, SalesController::class, 'process', ['action' => 'issue'], $company, ['invoice' => $requestId]);
        }

        return DB::table('sales_invoices')->where('public_id', $requestId)->first();
    }

    private function collect(stdClass $company, stdClass $bank, stdClass $invoice, string $date, float $share): void
    {
        $requestId = $this->uuid($company, 'receipt:'.$invoice->public_id);
        if (DB::table('sales_receipts')->where('public_id', $requestId)->exists()) {
            return;
        }
        $amount = $this->decimal((int) round((int) $invoice->total_cents * $share));
        $reference = 'DEP-'.$invoice->fiscal_number;
        $this->act($this->bookkeeper, SalesController::class, 'receipt', ['request_id' => $requestId, 'amount' => $amount, 'received_on' => $date,
            'reference' => $reference], $company, ['invoice' => $invoice->public_id]);
        $this->bankMovement($company, $bank, $date, $reference, 'Depósito de '.$invoice->customer_name, 'inflow', $amount);
    }

    private function purchase(stdClass $company, int $supplierId, string $date, string $account, string $treatment, string $description, string $amount, string $number): stdClass
    {
        $requestId = $this->uuid($company, 'purchase:'.$number.':'.$supplierId);
        $supplier = DB::table('purchase_suppliers')->where('id', $supplierId)->first();
        $this->act($this->bookkeeper, PurchaseController::class, 'store', ['request_id' => $requestId, 'supplier_id' => $supplierId,
            'document_type' => $treatment === 'goods' && str_starts_with($account, '51') ? 'purchase' : 'expense', 'external_reference' => 'FAC-'.$number,
            'document_date' => $date, 'due_on' => CarbonImmutable::parse($date)->addDays((int) ($supplier->payment_terms_days ?? 15))->toDateString(),
            'fiscal_document_type' => 'FACT', 'fiscal_series' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $supplier->name) ?? 'PROV', 0, 4)),
            'fiscal_number' => $number, 'lines' => [['account_id' => $this->account($account), 'description' => $description, 'quantity' => 1,
                'unit_price' => $amount, 'tax_treatment' => $treatment]]], $company);
        $document = DB::table('purchase_documents')->where('public_id', $requestId)->first();
        if ($document->status === 'draft') {
            $this->act($this->owner, PurchaseController::class, 'process', ['action' => 'approve'], $company, ['document' => $requestId]);
        }

        return DB::table('purchase_documents')->where('public_id', $requestId)->first();
    }

    private function pay(stdClass $company, stdClass $bank, stdClass $document, string $date, bool $cashed): void
    {
        $requestId = $this->uuid($company, 'payment:'.$document->public_id);
        if (DB::table('purchase_payments')->where('public_id', $requestId)->exists()) {
            return;
        }
        $reference = 'CHQ-'.str_pad((string) $document->number, 5, '0', STR_PAD_LEFT);
        $amount = $this->decimal((int) $document->total_cents);
        $this->act($this->owner, PurchaseController::class, 'payment', ['request_id' => $requestId, 'amount' => $amount, 'paid_on' => $date, 'reference' => $reference],
            $company, ['document' => $document->public_id]);
        // An uncashed check reaches the bank in the first days of the next month.
        $clearedOn = $cashed ? $date : $this->paidOn(CarbonImmutable::parse($date)->startOfMonth()->addMonth()->toDateString(), 2);
        $this->bankMovement($company, $bank, $clearedOn, $reference, 'Cheque pagado a '.$document->supplier_name, 'outflow', $amount);
    }

    /** The bank charges a monthly fee; the first one is already booked, the last one waits for the reconciliation. */
    private function bankFee(stdClass $company, stdClass $bank, CarbonImmutable $month, bool $booked): void
    {
        $date = $month->endOfMonth()->toDateString();
        $reference = 'COM-'.$month->format('Ym');
        $this->bankMovement($company, $bank, $date, $reference, 'Comisión por manejo de cuenta', 'outflow', self::BANK_FEE);
        if ($booked) {
            $this->manualEntry($company, 'fee:'.$month->format('Ym'), $date, 'standard', 'Comisión bancaria por manejo de cuenta', $reference,
                [['account_id' => $this->account('5205'), 'debit' => self::BANK_FEE, 'credit' => '0'], ['account_id' => $this->account('1102'), 'debit' => '0', 'credit' => self::BANK_FEE]]);
        }
    }

    private function bankMovement(stdClass $company, stdClass $bank, string $date, string $reference, string $description, string $flow, string $amount): void
    {
        if (DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->exists()) {
            return;
        }
        $this->act($this->owner, BankController::class, 'storeTransaction', ['account_id' => $bank->id, 'transaction_date' => $date, 'reference' => $reference,
            'description' => $description, 'flow' => $flow, 'amount' => $amount], $company);
    }

    /** Automatic entries arrive as drafts for the owner's review: in the demo the owner has already posted them. */
    private function postDrafts(stdClass $company): void
    {
        $accounting = app(AccountingService::class);
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $company->id)->where('e.status', 'draft')->orderBy('e.entry_date')->orderBy('e.id')->get(['e.*']);
        foreach ($entries as $entry) {
            $accounting->post((int) $company->id, $entry, $this->owner);
        }
    }

    /** Bank account reconciled at the end of last month: a check in transit and the fee not yet booked explain the difference. */
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
                'description' => 'Cheque '.$check->reference.' en circulación, cobrado el '.CarbonImmutable::parse($check->transaction_date)->format('d/m/Y'), 'amount' => '-'.$this->decimal((int) $check->amount_cents)]);
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

    /** @param list<array{account_id: int, debit: string, credit: string}> $lines */
    private function manualEntry(stdClass $company, string $key, string $date, string $kind, string $description, string $reference, array $lines): void
    {
        $requestId = $this->uuid($company, $key);
        if (DB::table('journal_entries')->where('public_id', $requestId)->exists()) {
            return;
        }
        $accounting = app(AccountingService::class);
        $periodId = (int) DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->value('id');
        $accounting->createDraft($this->organization, $this->owner, $company, ['request_id' => $requestId, 'period_id' => $periodId, 'entry_date' => $date,
            'description' => $description, 'reference' => $reference, 'kind' => $kind], $accounting->lines((int) $company->id, $lines));
    }

    /**
     * Runs a controller action as the given user, exactly as the form on screen would.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $parameters
     */
    private function act(User $user, string $controller, string $action, array $input, stdClass $company, array $parameters = []): void
    {
        $request = Request::create('/', 'POST', $input);
        $request->setUserResolver(fn (): User => $user);
        Auth::setUser($user);
        app()->instance('request', $request);
        app()->call(app($controller)->{$action}(...), ['request' => $request, 'company' => (string) $company->public_id, ...$parameters]);
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
        return Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.essential-demo.'.$company->id.'.'.$key)->toString();
    }

    /**
     * Fictitious clients of the practice. Sales repeat every month: [customer, day, lines [description, quantity, unit price with VAT, treatment], share collected].
     * Purchases: [supplier, day, account, treatment, description, total with VAT].
     *
     * @return list<array<string, mixed>>
     */
    private function profiles(): array
    {
        return [
            [
                'tax_id' => '4455667-8', 'name' => 'Panadería La Esperanza',
                'revenue' => '4201', 'capital' => '60000.00', 'series' => 'PLE',
                'bank' => ['Banco Agromercantil', 'Cuenta monetaria Panadería La Esperanza', '3104'],
                'customers' => [
                    ['Cafetería El Portal', '5566778-1', 'compras@elportal.test', 15],
                    ['Hotel Casa Antigua', '8899001-2', 'proveeduria@casaantigua.test', 30],
                    ['Supermercado La Económica', '2233445-6', 'cuentasporpagar@laeconomica.test', 30],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Molinos de Harina del Sur, S.A.', '6677889-0', 'ventas@molinosdelsur.test', '+502 2410 3300', 30],
                    ['Azucarera El Trapiche', '3344112-9', 'pedidos@eltrapiche.test', '+502 2385 1200', 15],
                    ['Inmobiliaria Los Álamos', '4433221-7', 'cobros@losalamos.test', '+502 2333 4455', 0],
                    ['Energía del Valle, S.A.', '1122334-4', 'facturacion@energiadelvalle.test', '+502 2277 0000', 10],
                ],
                'sales' => [
                    ['Cafetería El Portal', 4, [['Pan francés (ciento)', 12, '85.00', 'goods'], ['Pan dulce surtido (docena)', 20, '42.00', 'goods']], 1.0],
                    ['Hotel Casa Antigua', 9, [['Pan para desayuno buffet (ciento)', 25, '95.00', 'goods'], ['Pasteles para eventos', 3, '450.00', 'goods']], 1.0],
                    ['Supermercado La Económica', 16, [['Pan de molde', 120, '24.50', 'goods'], ['Champurradas (paquete)', 80, '18.00', 'goods']], 1.0],
                    ['Consumidor final', 28, [['Ventas de mostrador del mes', 1, '18650.00', 'goods']], 1.0],
                ],
                'purchases' => [
                    ['Molinos de Harina del Sur, S.A.', 3, '5103', 'goods', 'Harina de trigo, 40 quintales', '9800.00'],
                    ['Azucarera El Trapiche', 6, '5103', 'goods', 'Azúcar y manteca para producción', '3150.00'],
                    ['Inmobiliaria Los Álamos', 1, '5203', 'services', 'Alquiler del local', '5600.00'],
                    ['Energía del Valle, S.A.', 12, '5204', 'services', 'Energía eléctrica de hornos y local', '2380.00'],
                ],
            ],
            [
                'tax_id' => '7788990-1', 'name' => 'Ferretería El Tornillo',
                'revenue' => '4201', 'capital' => '120000.00', 'series' => 'FET',
                'bank' => ['Banco Industrial', 'Cuenta monetaria Ferretería El Tornillo', '5571'],
                'customers' => [
                    ['Constructora Altos del Lago, S.A.', '7766554-3', 'compras@altosdellago.test', 30],
                    ['Taller Mecánico Hermanos López', '5544332-1', 'taller.lopez@correo.test', 15],
                    ['Inmobiliaria Vista Verde', '6655443-2', 'proyectos@vistaverde.test', 30],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Importadora de Herramientas del Centro', '8877665-4', 'ventas@herramientascentro.test', '+502 2440 5500', 30],
                    ['Cementos y Agregados del Norte', '2211009-8', 'pedidos@cementosnorte.test', '+502 7950 1100', 15],
                    ['Pinturas Arcoíris, S.A.', '3322110-9', 'distribuidores@arcoiris.test', '+502 2360 7788', 30],
                    ['Inmobiliaria Los Álamos', '4433221-7', 'cobros@losalamos.test', '+502 2333 4455', 0],
                    ['Transportes Rápidos del Altiplano', '9911223-6', 'fletes@rapidosaltiplano.test', '+502 7760 2020', 0],
                ],
                'sales' => [
                    ['Constructora Altos del Lago, S.A.', 5, [['Cemento gris, saco de 42.5 kg', 200, '82.00', 'goods'], ['Varilla de hierro 3/8"', 150, '48.50', 'goods']], 1.0],
                    ['Taller Mecánico Hermanos López', 11, [['Juego de llaves combinadas', 4, '685.00', 'goods'], ['Aceite multiusos (caja)', 6, '210.00', 'goods']], 1.0],
                    ['Inmobiliaria Vista Verde', 18, [['Pintura látex, cubeta 5 galones', 30, '595.00', 'goods'], ['Brochas y rodillos (kit)', 25, '95.00', 'goods']], 1.0],
                    ['Consumidor final', 27, [['Ventas de mostrador del mes', 1, '34800.00', 'goods']], 1.0],
                ],
                'purchases' => [
                    ['Cementos y Agregados del Norte', 2, '5103', 'goods', 'Cemento gris, 300 sacos', '19800.00'],
                    ['Importadora de Herramientas del Centro', 7, '5103', 'goods', 'Herramienta manual y eléctrica', '14650.00'],
                    ['Pinturas Arcoíris, S.A.', 13, '5103', 'goods', 'Pintura látex y esmaltes', '12400.00'],
                    ['Inmobiliaria Los Álamos', 1, '5203', 'services', 'Alquiler de bodega y sala de ventas', '8500.00'],
                    ['Transportes Rápidos del Altiplano', 20, '5201', 'services', 'Fletes de entrega a obras', '2750.00'],
                ],
            ],
            [
                'tax_id' => '1239876-5', 'name' => 'Clínica Dental Sonrisas',
                'revenue' => '4101', 'capital' => '45000.00', 'series' => 'CDS',
                'bank' => ['Banco G&T Continental', 'Cuenta monetaria Clínica Dental Sonrisas', '8826'],
                'customers' => [
                    ['Aseguradora Vida Plena, S.A.', '9900112-3', 'reclamos@vidaplena.test', 30],
                    ['Colegio San Ángel', '1188776-6', 'administracion@sanangel.test', 30],
                    ['Servicios Logísticos Quetzal', '7711223-5', 'rrhh@logisticaquetzal.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Depósito Dental Guatemala', '5599887-1', 'ventas@depositodental.test', '+502 2332 9090', 30],
                    ['Laboratorio Dental Precisión', '6611447-2', 'casos@labprecision.test', '+502 2251 3434', 15],
                    ['Inmobiliaria Los Álamos', '4433221-7', 'cobros@losalamos.test', '+502 2333 4455', 0],
                    ['Energía del Valle, S.A.', '1122334-4', 'facturacion@energiadelvalle.test', '+502 2277 0000', 10],
                ],
                'sales' => [
                    ['Aseguradora Vida Plena, S.A.', 6, [['Tratamientos cubiertos por póliza colectiva', 1, '14250.00', 'services']], 1.0],
                    ['Colegio San Ángel', 12, [['Jornada de evaluación dental escolar', 1, '6800.00', 'services']], 1.0],
                    ['Servicios Logísticos Quetzal', 19, [['Limpiezas dentales para colaboradores', 18, '275.00', 'services']], 1.0],
                    ['Consumidor final', 28, [['Consultas y tratamientos a pacientes particulares', 1, '21400.00', 'services']], 1.0],
                ],
                'purchases' => [
                    ['Depósito Dental Guatemala', 4, '5107', 'goods', 'Resinas, anestésicos y material desechable', '6450.00'],
                    ['Laboratorio Dental Precisión', 10, '5107', 'services', 'Coronas y prótesis de laboratorio', '7800.00'],
                    ['Inmobiliaria Los Álamos', 1, '5203', 'services', 'Alquiler de la clínica', '7200.00'],
                    ['Energía del Valle, S.A.', 14, '5204', 'services', 'Energía eléctrica de la clínica', '1650.00'],
                ],
            ],
            [
                'tax_id' => '5512347-0', 'name' => 'Arquitectura Línea Recta',
                'revenue' => '4101', 'capital' => '80000.00', 'series' => 'ALR',
                'bank' => ['Banco Industrial', 'Cuenta monetaria Arquitectura Línea Recta', '6640'],
                'customers' => [
                    ['Constructora Altos del Lago, S.A.', '7766554-3', 'proyectos@altosdellago.test', 30],
                    ['Inversiones Santa Clara, S.A.', '4466880-2', 'gerencia@santaclara.test', 30],
                    ['Familia Morales Castillo', '2758493-1', 'morales.castillo@correo.test', 15],
                ],
                'suppliers' => [
                    ['Estudio de Ingeniería Estructural Cifuentes', '3698521-4', 'calculo@cifuentes.test', '+502 2369 8800', 15],
                    ['Impresiones y Planos Express', '1472583-6', 'ploteo@planosexpress.test', '+502 2254 1010', 0],
                    ['Inmobiliaria Los Álamos', '4433221-7', 'cobros@losalamos.test', '+502 2333 4455', 0],
                    ['Software de Diseño Centroamérica', '8529637-0', 'licencias@sdca.test', '+502 2300 4545', 0],
                ],
                'sales' => [
                    ['Constructora Altos del Lago, S.A.', 5, [['Supervisión de obra, avance mensual', 1, '18500.00', 'services']], 1.0],
                    ['Inversiones Santa Clara, S.A.', 14, [['Diseño arquitectónico de local comercial, fase de anteproyecto', 1, '22400.00', 'services']], 1.0],
                    ['Familia Morales Castillo', 22, [['Planos constructivos de vivienda unifamiliar', 1, '9750.00', 'services'], ['Trámite de licencia municipal', 1, '1800.00', 'services']], 1.0],
                ],
                'purchases' => [
                    ['Inmobiliaria Los Álamos', 1, '5203', 'services', 'Alquiler de oficina', '6200.00'],
                    ['Software de Diseño Centroamérica', 3, '5201', 'services', 'Suscripción mensual de software de diseño', '1450.00'],
                    ['Impresiones y Planos Express', 9, '5201', 'goods', 'Impresión de planos y maquetas', '980.00'],
                    ['Estudio de Ingeniería Estructural Cifuentes', 17, '5208', 'services', 'Cálculo estructural subcontratado', '8400.00'],
                ],
            ],
            [
                'tax_id' => '6789012-3', 'name' => 'Agroservicios San Isidro', 'keywords' => ['agr', 'distribu', 'agro'],
                'revenue' => '4201', 'capital' => '150000.00', 'series' => 'AGR',
                'bank' => ['Banco de Desarrollo Rural', 'Cuenta monetaria agroservicio', '7215'],
                'customers' => [
                    ['Cooperativa Agrícola Nuevo Amanecer', '3579135-7', 'compras@nuevoamanecer.test', 30],
                    ['Agropecuaria Los Volcanes, S.A.', '2468024-6', 'proveeduria@losvolcanes.test', 30],
                    ['Hortalizas del Altiplano', '1357913-5', 'pedidos@hortalizasaltiplano.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Fertilizantes de Centroamérica, S.A.', '9753197-5', 'ventas@fertica.test', '+502 2420 1500', 30],
                    ['Semillas Mejoradas del Pacífico', '8642086-4', 'pedidos@semillaspacifico.test', '+502 7880 3030', 30],
                    ['Inmobiliaria Los Álamos', '4433221-7', 'cobros@losalamos.test', '+502 2333 4455', 0],
                    ['Transportes Rápidos del Altiplano', '9911223-6', 'fletes@rapidosaltiplano.test', '+502 7760 2020', 0],
                ],
                'sales' => [
                    ['Cooperativa Agrícola Nuevo Amanecer', 5, [['Fertilizante 15-15-15, quintal', 120, '285.00', 'goods'], ['Urea 46 %, quintal', 60, '310.00', 'goods']], 1.0],
                    ['Agropecuaria Los Volcanes, S.A.', 12, [['Semilla de maíz híbrido, bolsa 25 kg', 40, '1150.00', 'goods']], 1.0],
                    ['Hortalizas del Altiplano', 19, [['Fungicida agrícola, galón', 30, '245.00', 'goods'], ['Bomba de mochila 20 litros', 6, '525.00', 'goods']], 1.0],
                    ['Consumidor final', 27, [['Ventas de mostrador del mes', 1, '26900.00', 'goods']], 1.0],
                ],
                'purchases' => [
                    ['Fertilizantes de Centroamérica, S.A.', 2, '5103', 'goods', 'Fertilizantes granulados, 200 quintales', '41500.00'],
                    ['Semillas Mejoradas del Pacífico', 6, '5103', 'goods', 'Semilla certificada de maíz y frijol', '28600.00'],
                    ['Inmobiliaria Los Álamos', 1, '5203', 'services', 'Alquiler de bodega y sala de ventas', '7800.00'],
                    ['Transportes Rápidos del Altiplano', 16, '5201', 'services', 'Fletes de entrega a fincas', '3200.00'],
                ],
            ],
            [
                'tax_id' => '5432109-8', 'name' => 'Finca El Mirador', 'keywords' => ['finca', 'cafe', 'café', 'hacienda'],
                'revenue' => '4201', 'capital' => '200000.00', 'series' => 'FEM',
                'bank' => ['Banco de Desarrollo Rural', 'Cuenta monetaria de la finca', '4093'],
                'customers' => [
                    ['Exportadora de Café de Altura, S.A.', '7531975-3', 'compras@cafealtura.test', 30],
                    ['Beneficio La Cumbre', '6420864-2', 'recepcion@lacumbre.test', 15],
                    ['Mercado Mayorista Central', '5319753-1', 'administracion@mayorista.test', 15],
                ],
                'suppliers' => [
                    ['Fertilizantes de Centroamérica, S.A.', '9753197-5', 'ventas@fertica.test', '+502 2420 1500', 30],
                    ['Agroquímicos del Sur', '4208642-0', 'ventas@agroquimicossur.test', '+502 7830 4141', 30],
                    ['Transportes Rápidos del Altiplano', '9911223-6', 'fletes@rapidosaltiplano.test', '+502 7760 2020', 0],
                    ['Energía del Valle, S.A.', '1122334-4', 'facturacion@energiadelvalle.test', '+502 2277 0000', 10],
                ],
                'sales' => [
                    ['Exportadora de Café de Altura, S.A.', 8, [['Café pergamino, quintal', 45, '1650.00', 'goods']], 1.0],
                    ['Beneficio La Cumbre', 15, [['Café en cereza, quintal', 120, '420.00', 'goods']], 1.0],
                    ['Mercado Mayorista Central', 23, [['Banano de sombra, caja', 300, '65.00', 'goods'], ['Aguacate Hass, caja', 80, '210.00', 'goods']], 1.0],
                ],
                'purchases' => [
                    ['Fertilizantes de Centroamérica, S.A.', 3, '5103', 'goods', 'Fertilizante para cafetal', '24800.00'],
                    ['Agroquímicos del Sur', 9, '5103', 'goods', 'Control de roya y broca', '9650.00'],
                    ['Transportes Rápidos del Altiplano', 18, '5201', 'services', 'Flete de café al beneficio', '4100.00'],
                    ['Energía del Valle, S.A.', 12, '5204', 'services', 'Energía del beneficio húmedo', '2950.00'],
                ],
            ],
        ];
    }
}
