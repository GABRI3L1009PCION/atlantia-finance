<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Http\Controllers\AccountingDocumentController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\OfficeAdminController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\WorkManagementController;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\Accounting\AccountReconciliationService;
use App\Services\Accounting\ConsolidationService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\InventoryCountService;
use App\Services\Accounting\InventoryService;
use App\Services\Accounting\ProductionService;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\ClientPortal\ClientAccessAdminService;
use App\Services\ClientPortal\ClientDocumentService;
use App\Services\ClientPortal\ClientMessageService;
use App\Services\ClientPortal\ClientRequestService;
use App\Services\ClientPortal\PublishedReportService;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\Fiscal\VatBookService;
use App\Services\Fiscal\WithholdingService;
use App\Services\Integrations\ApiTokenService;
use App\Services\Integrations\WebhookDeliveryService;
use App\Services\Integrations\WebhookEndpointService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\SupervisorService;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollRecordService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Treasury\TreasuryBankService;
use App\Services\Treasury\TreasuryPaymentService;
use App\Services\Treasury\TreasuryReceiptService;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\TrainingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use App\Support\SpanishMonth;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon as IlluminateCarbon;
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
use Throwable;

/**
 * Escenario integral de demostración del Plan Corporativo (`corporate`): la firma «Grupo Contable Atlántico Norte» con
 * 8 sedes (Puerto Barrios, Santo Tomás, Morales, Livingston, Zacapa, Chiquimula, Cobán y Ciudad de Guatemala; revisión
 * de uno o dos niveles según la sede), 49 usuarios internos con sus roles reales y cuatro roles personalizados, y 96
 * empresas cliente: 32 con tres meses completos (inventario, producción, nómina, activos fijos, bancos, retenciones,
 * libros de IVA, revisión y cierre supervisado) y 64 con operación pequeña del mes. Además: tres grupos consolidados con
 * participaciones del 100 % y parciales y operaciones entre sus empresas con el NIT exacto de la contraparte; portal del
 * cliente; obligaciones, tareas e incidencias; capacitación; llaves de la API v1 (activa, restringida a una empresa,
 * revocada y vencida) con llamadas reales dentro del proceso; y webhooks hacia destinos simulados.
 *
 * Cada paso lo ejecuta quien tiene el permiso real en su sede. El propietario instala la firma (sedes, políticas, roles,
 * integraciones) y al final se incorpora la Administradora General: desde ese momento el Director queda en solo
 * supervisión, como manda el sistema, y las acciones de administración en ejecuciones posteriores las hace ella.
 *
 * Seguridad: solo corre fuera de producción; correos, notificaciones y trabajos en cola se simulan; todo HTTP saliente
 * responde con un servidor simulado (Http::fake) y los destinos usan dominios .test; no se certifica FEL, no se cobra,
 * no se consulta la IA ni se llama a bancos. Las llaves de API y los secretos de webhook nunca se guardan en claro (la
 * llave queda como huella SHA-256 y el secreto cifrado), ni se imprimen. Es idempotente y solo toca su organización.
 *
 * @phpstan-import-type Company from PlanCorporativoDemoCatalog
 * @phpstan-import-type Sector from PlanMultiOficinaDemoCatalog
 * @phpstan-import-type Sale from PlanMultiOficinaDemoCatalog
 * @phpstan-import-type Purchase from PlanMultiOficinaDemoCatalog
 *
 * @phpstan-type Profile array{company: Company, sector: Sector, customers: list<array{0: string, 1: string, 2: string|null, 3: int}>, suppliers: list<array{0: string, 1: string, 2: string, 3: string, 4: int}>, sales: list<Sale>, purchases: list<Purchase>}
 * @phpstan-type KardexEvent array{date: string, order: int, type: string, item: string, quantity: int, cost: string|null, reference: string}
 */
class PlanCorporativoDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const PLAN = 'corporate';

    private const BANK_FEE = '45.00';

    private const PETTY_CASH = '1500.00';

    private const ASSET_SUPPLIER = ['Distribuidora de Vehículos y Maquinaria del Atlántico', '7019283-5', 'ventas@vehiculosatlantico.test', '+502 7948 9300', 30];

    private Organization $organization;

    /** La organización cargada desde el propietario (con su pivote de rol), como la usan las pantallas. */
    private Organization $member;

    private User $owner;

    /** @var array<string, User> El equipo por clave del catálogo. */
    private array $team = [];

    /** @var array<string, int> Sedes por código. */
    private array $offices = [];

    /** @var array<string, string> Código interno de cada rol personalizado por clave del catálogo. */
    private array $customRoleCodes = [];

    /** @var list<string> Resultado de cada llamada a la API v1 (método, ruta y código HTTP). */
    private array $apiResults = [];

    /** Quien lleva los libros de la empresa que se está cargando. */
    private User $bookkeeper;

    /** @var array<string, int> Cuentas contables de la empresa por código. */
    private array $accounts = [];

    /** @var list<KardexEvent> */
    private array $kardex = [];

    /** @var list<array{company: stdClass, date: string, reference: string, product: int, flour: int, sugar: int}> Órdenes de producción por registrar en el kardex. */
    private array $productionPlan = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{0: string, 1: string, 2: string}> Usuarios creados en esta ejecución con su contraseña aleatoria (correo, nombre, contraseña). */
    private array $credentials = [];

    public function run(): void
    {
        $this->refuseInProduction();
        $this->call([PlanCatalogSeeder::class, PayrollParameterSeeder::class]);
        // Nada sale del proceso: correos, notificaciones y trabajos en cola se simulan, y cualquier HTTP saliente
        // (webhooks incluidos) responde con un servidor simulado. Los destinos de demostración usan dominios .test, que
        // no resuelven, así que la validación DNS de URL se omite solo en este proceso.
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://erp.distribuidoraelprogreso.test/*' => Http::response(['recibido' => true], 200),
            'https://bi.grupocontableatlantico.test/*' => Http::response('Servicio en mantenimiento', 503),
            '*' => Http::response('', 404),
        ]);
        config(['integrations.webhooks.resolve_dns' => false]);

        DB::transaction(fn () => $this->firm());
        $this->member = $this->owner->organizations()->whereKey($this->organization->id)->firstOrFail();
        $this->attempt(null, 'las políticas de las oficinas', fn () => $this->officePolicies());
        $this->attempt(null, 'los roles personalizados', fn () => $this->customRoles());
        DB::transaction(fn () => $this->joinTeam());

        $companies = $this->clients();
        $this->attempt(null, 'los webhooks', fn () => $this->webhookEndpoints($companies));
        $months = $this->months();
        foreach ($companies as [$company, $profile]) {
            $this->attempt($company, 'la contabilidad', fn () => $profile['company']['full'] ? $this->books($company, $profile, $months) : $this->lightBooks($company, $profile, $months[2]));
        }
        $perOffice = [];
        foreach ($companies as [$company, $profile]) {
            if (! $profile['company']['full']) {
                continue;
            }
            $office = $profile['company']['office'];
            $position = $perOffice[$office] = ($perOffice[$office] ?? -1) + 1;
            $this->periodReviews($company, $position, $months);
            $this->attempt($company, 'las partidas en revisión', fn () => $this->journalWorkflow($company, $profile, $position, $months[2]));
            if ($position === 1) {
                $this->attempt($company, 'la incidencia operativa', fn () => $this->incident($company));
            }
        }
        foreach ($companies as [$company, $profile]) {
            if (in_array($profile['company']['name'], PlanCorporativoDemoCatalog::portalCompanies(), true)) {
                $this->attempt($company, 'el portal del cliente', fn () => $this->clientPortal($company, $profile, $months));
            }
        }
        $this->attempt($companies[0][0], 'los grupos consolidados', fn () => $this->consolidationGroups($companies));
        $this->obligations(array_values(array_filter($companies, fn (array $pair): bool => $pair[1]['company']['full'])), $months);
        $this->tasks($companies, $months);
        $this->attempt(null, 'la capacitación', fn () => $this->training());
        $this->apiScenarios($companies);
        $this->attempt(null, 'las entregas de webhooks', fn () => $this->deliverWebhooks());
        // La administración general se delega cuando la firma ya está en marcha: desde ahí el Director solo supervisa.
        DB::transaction(fn () => $this->joinFirm($this->catalogMember('admin-general')));

        $this->report(array_map(fn (array $pair): int => (int) $pair[0]->id, $companies));
    }

    /** La firma, su perfil de facturación, la oficina principal y la suscripción; las demás sedes las crea la administración general. */
    private function firm(): void
    {
        $firm = PlanCorporativoDemoCatalog::firm();
        $ownerData = $this->catalogMember('owner');
        $this->owner = $this->user($ownerData['email'], $ownerData['name']);

        $organization = Organization::query()->withTrashed()->firstOrNew(['country_code' => 'GT', 'tax_identifier' => PlanCorporativoDemoCatalog::ORGANIZATION_TAX_ID]);
        $organization->fill(['name' => $firm['name'], 'legal_name' => $firm['legal_name'], 'currency_code' => 'GTQ', 'timezone' => 'America/Guatemala', 'status' => 'active']);
        $organization->deleted_at = null;
        $organization->save();
        $this->organization = $organization;
        DB::table('organization_user')->updateOrInsert(['organization_id' => $organization->id, 'user_id' => $this->owner->id],
            ['role' => 'owner', 'status' => 'active', 'joined_at' => now()->subYear(), 'invited_by' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_billing_profiles')->updateOrInsert(['organization_id' => $organization->id],
            [...$firm['billing'], 'created_at' => now(), 'updated_at' => now()]);

        $central = PlanCorporativoDemoCatalog::offices()[0];
        $officeId = DB::table('offices')->where('organization_id', $organization->id)->where('is_primary', true)->value('id')
            ?? DB::table('offices')->insertGetId(['organization_id' => $organization->id, 'public_id' => (string) Str::uuid(), 'code' => $central['code'], 'name' => $central['name'],
                'email' => 'central@'.PlanCorporativoDemoCatalog::DOMAIN, 'phone' => $central['phone'], 'address_line_1' => $central['address'], 'city' => $central['city'], 'state' => $central['state'],
                'country_code' => 'GT', 'timezone' => 'America/Guatemala', 'is_primary' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->offices[$central['code']] = (int) $officeId;
        DB::table('office_user')->updateOrInsert(['office_id' => (int) $officeId, 'user_id' => $this->owner->id],
            ['role' => 'manager', 'status' => 'active', 'assigned_at' => now()->subYear(), 'created_at' => now(), 'updated_at' => now()]);

        $this->subscription($organization);
        app(WorkAuthorizationService::class)->ensureDefaults($organization);
        $this->assignRole($this->owner->id, 'owner_director', null);
        $this->team['owner'] = $this->owner;

        // Las otras siete sedes, con la acción real de la pantalla Oficinas (solo la administración general puede crearlas).
        foreach (array_slice(PlanCorporativoDemoCatalog::offices(), 1) as $office) {
            $id = DB::table('offices')->where('organization_id', $organization->id)->where('code', $office['code'])->value('id');
            if ($id === null) {
                $this->actOn($this->owner, WorkManagementController::class, 'storeOffice', ['code' => $office['code'], 'name' => $office['name'], 'email' => Str::slug($office['name'], '.').'@'.PlanCorporativoDemoCatalog::DOMAIN,
                    'phone' => $office['phone'], 'address_line_1' => $office['address'], 'city' => $office['city'], 'state' => $office['state'], 'country_code' => 'GT', 'timezone' => 'America/Guatemala', 'status' => 'active']);
                $id = DB::table('offices')->where('organization_id', $organization->id)->where('code', $office['code'])->value('id');
            }
            $this->offices[$office['code']] = (int) $id;
        }
        $limit = app(WorkspaceContext::class)->limit($organization, 'offices');
        if (count($this->offices) > $limit) {
            throw new RuntimeException('El Plan Corporativo admite '.$limit.' oficinas.');
        }
    }

    /** Plazos fiscales, flujo de revisión (uno o dos niveles), tramos de antigüedad y políticas de cierre de cada sede. */
    private function officePolicies(): void
    {
        foreach (PlanCorporativoDemoCatalog::offices() as $office) {
            $this->actOn($this->admin(), OfficeAdminController::class, 'updatePolicies', ['review_workflow' => $office['workflow'], 'fiscal_internal_lead_days' => $office['internal_days'],
                'fiscal_review_lead_days' => $office['review_days'], 'fiscal_close_policy' => 'warn', 'payment_approval_tiers' => [], 'payment_self_approval' => false,
                'treasury_close_policy' => 'warn', 'receivables_aging_buckets' => $office['buckets'], 'receivables_close_policy' => 'warn'], ['office' => $this->offices[$office['code']]]);
        }
    }

    /** Roles personalizados creados con la acción real de Roles y permisos, solo con permisos de consulta y reporte. */
    private function customRoles(): void
    {
        foreach (PlanCorporativoDemoCatalog::customRoles() as $key => $role) {
            if (! DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('name', $role['name'])->whereNull('deleted_at')->exists()) {
                $permissions = DB::table('workspace_permissions')->whereIn('code', $role['permissions'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $this->actOn($this->admin(), WorkManagementController::class, 'storeRole', ['name' => $role['name'], 'description' => $role['description'], 'permission_ids' => $permissions]);
            }
            $this->customRoleCodes[$key] = (string) DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('name', $role['name'])->whereNull('deleted_at')->value('code');
        }
    }

    /** Todo el equipo menos la Administradora General, en sus sedes y con su rol (de sede o de toda la firma). */
    private function joinTeam(): void
    {
        foreach (PlanCorporativoDemoCatalog::team() as $member) {
            if (! in_array($member['key'], ['owner', 'admin-general'], true)) {
                $this->joinFirm($member);
            }
        }
        // El propietario está en todas las sedes.
        foreach ($this->offices as $officeId) {
            DB::table('office_user')->updateOrInsert(['office_id' => $officeId, 'user_id' => $this->owner->id],
                ['role' => 'manager', 'status' => 'active', 'assigned_at' => now()->subYear(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Incorpora a una persona: organización, sedes donde trabaja y su rol (por sede o de toda la firma), dentro del límite
     * de 60 usuarios internos.
     *
     * @param  array{key: string, name: string, email: string, role: string, offices: list<string>|null, custom: string|null}  $member
     */
    private function joinFirm(array $member): void
    {
        $user = $this->user($member['email'], $member['name']);
        $this->team[$member['key']] = $user;
        $joined = DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $user->id)->exists();
        if (! $joined && $this->internalUsers() >= app(WorkspaceContext::class)->limit($this->organization, 'users')) {
            throw new RuntimeException('El Plan Corporativo no admite más usuarios: '.$member['name'].' no se agregó.');
        }
        DB::table('organization_user')->updateOrInsert(['organization_id' => $this->organization->id, 'user_id' => $user->id],
            ['role' => 'member', 'status' => 'active', 'joined_at' => now()->subMonths(10), 'invited_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $codes = $member['offices'] ?? array_keys($this->offices);
        foreach ($codes as $code) {
            DB::table('office_user')->updateOrInsert(['office_id' => $this->offices[$code], 'user_id' => $user->id],
                ['role' => $member['role'] === 'office_admin' ? 'admin' : 'member', 'status' => 'active', 'assigned_at' => now()->subMonths(10), 'created_at' => now(), 'updated_at' => now()]);
        }
        $role = $member['custom'] === null ? $member['role'] : ($this->customRoleCodes[$member['custom']] ?? '');
        if ($role === '') {
            return;
        }
        foreach ($member['offices'] === null ? [null] : array_map(fn (string $code): int => $this->offices[$code], $member['offices']) as $officeId) {
            $this->assignRole($user->id, $role, $officeId);
        }
    }

    /** @return array{key: string, name: string, email: string, role: string, offices: list<string>|null, custom: string|null} */
    private function catalogMember(string $key): array
    {
        foreach (PlanCorporativoDemoCatalog::team() as $member) {
            if ($member['key'] === $key) {
                return $member;
            }
        }

        throw new RuntimeException("Falta {$key} en el equipo de demostración.");
    }

    /** Usuarios internos activos: los contactos del cliente en el portal no ocupan asientos del plan. */
    private function internalUsers(): int
    {
        $clientRole = DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('code', 'client_user')->value('id');

        return DB::table('organization_user')->where('organization_id', $this->organization->id)->where('status', 'active')
            ->whereNotIn('user_id', DB::table('user_role_assignments')->where('organization_id', $this->organization->id)->where('workspace_role_id', (int) $clientRole)
                ->where('status', 'active')->select('user_id'))->count();
    }

    private function user(string $email, string $name): User
    {
        $email = str_contains($email, '@') ? $email : $email.'@'.PlanCorporativoDemoCatalog::DOMAIN;
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

    /**
     * Las 96 empresas, creadas con el servicio de la pantalla Clientes en su sede (catálogo, perfil fiscal, impuestos,
     * contador encargado y auxiliar) y sus responsables: supervisor, encargado fiscal, tesorería, cuentas por cobrar y por
     * pagar, nómina, auditoría y consulta.
     *
     * @return list<array{0: stdClass, 1: Profile}>
     */
    private function clients(): array
    {
        $service = app(CompanyAdministrationService::class);
        $groupNames = [];
        foreach (PlanCorporativoDemoCatalog::groups() as $group) {
            $groupNames = [...$groupNames, $group['parent'], ...array_column($group['members'], 0)];
        }
        $clients = [];
        foreach (PlanCorporativoDemoCatalog::companies() as $data) {
            $officeId = $this->offices[$data['office']];
            $people = PlanCorporativoDemoCatalog::officeStaff($data['office']);
            $company = DB::table('companies')->where('organization_id', $this->organization->id)->where('tax_id', $data['tax_id'])->first();
            if ($company === null) {
                $created = $service->create($this->member, $this->owner, ['name' => $data['name'], 'tax_id' => $data['tax_id'], 'email' => $data['email'], 'office_id' => $officeId,
                    'accountant_id' => $this->team[$people['accountant']]->id, 'assistant_id' => $this->team[$people['assistant']]->id]);
                DB::table('companies')->where('id', $created['id'])->update(['trade_name' => $data['trade_name'], 'phone' => $data['phone'], 'address' => $data['address'],
                    'legal_representative' => $data['contact'], 'contact_name' => $data['contact'], 'created_at' => now()->subMonths(6)]);
                $company = DB::table('companies')->where('id', $created['id'])->firstOrFail();
            }
            $responsibilities = [['supervisor', $people['supervisor']], ['tax_specialist', $people['tax']], ['treasury', 'treasury'], ['accounts_receivable', 'receivables'], ['accounts_payable', 'payables']];
            if ($data['payroll']) {
                $responsibilities[] = ['payroll', 'payroll'];
            }
            if (in_array($data['name'], $groupNames, true)) {
                $responsibilities[] = ['auditor', 'auditor'];
            }
            if ($data['office'] === 'GUA-01') {
                $responsibilities[] = ['read_only', 'readonly'];
            }
            foreach ($responsibilities as [$role, $key]) {
                $this->responsible($company, $officeId, $role, $this->team[$key]);
            }
            $clients[] = [$company, $this->profile($data)];
        }

        return $clients;
    }

    /**
     * Operaciones de la empresa: las de su sector con sus propios montos, más las que tiene con otras empresas de su grupo,
     * registradas con el nombre y el NIT exactos de la contraparte.
     *
     * @param  Company  $company
     * @return Profile
     */
    private function profile(array $company): array
    {
        $sector = PlanMultiOficinaDemoCatalog::sectors()[$company['sector']];
        $scale = $company['scale'];
        $sales = array_map(fn (array $sale): array => [$sale[0], $sale[1], array_map(fn (array $line): array => [$line[0], $line[1], $this->scaled($line[2], $scale), $line[3], $line[4]], $sale[2]), $sale[3]], $sector['sales']);
        $purchases = array_map(fn (array $purchase): array => [$purchase[0], $purchase[1], $purchase[2], $purchase[3], $purchase[4], $this->scaled($purchase[5], $scale), $purchase[6], $purchase[7], $purchase[8]], $sector['purchases']);
        $customers = $sector['customers'];
        $suppliers = $sector['suppliers'];
        $all = array_column(PlanCorporativoDemoCatalog::companies(), null, 'name');
        foreach (PlanCorporativoDemoCatalog::intercompany() as [$seller, $buyer, $day, $description, $amount, $treatment, $account]) {
            if ($seller === $company['name']) {
                $customers[] = [$buyer, $all[$buyer]['tax_id'], $all[$buyer]['email'], 30];
                $sales[] = [$buyer, $day, [[$description, 1, $amount, $treatment, null]], false];
            }
            if ($buyer === $company['name']) {
                $suppliers[] = [$seller, $all[$seller]['tax_id'], $all[$seller]['email'], $all[$seller]['phone'], 30];
                $purchases[] = [$seller, $day, $account, $treatment, $description, $amount, false, null, 0];
            }
        }
        if ($company['asset'] !== null) {
            $suppliers[] = self::ASSET_SUPPLIER;
        }

        return ['company' => $company, 'sector' => $sector, 'customers' => array_values(array_unique($customers, SORT_REGULAR)),
            'suppliers' => array_values(array_unique($suppliers, SORT_REGULAR)), 'sales' => $sales, 'purchases' => $purchases];
    }

    /**
     * Empresa con operación pequeña: apertura, una venta al contado cobrada, una compra al crédito y su contabilización en el
     * mes en curso (los meses anteriores, sin movimientos, ya estaban declarados).
     *
     * @param  Profile  $profile
     */
    private function lightBooks(stdClass $company, array $profile, CarbonImmutable $month): void
    {
        $this->bookkeeper = $this->bookkeeperOf($company);
        $automation = app(AccountingAutomationService::class);
        $mapping = $automation->ensureMapping((int) $company->id);
        $this->accounts = DB::table('ledger_accounts')->where('company_id', $company->id)->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        if ($mapping !== null && $profile['sector']['revenue'] !== '4101') {
            $automation->updateMapping((int) $company->id, ['cash_account_id' => $mapping->cash_account_id, 'receivable_account_id' => $mapping->receivable_account_id,
                'revenue_account_id' => $this->account($profile['sector']['revenue']), 'payable_account_id' => $mapping->payable_account_id,
                'default_expense_account_id' => $mapping->default_expense_account_id]);
        }
        $this->openPeriods($company, [$month]);
        $this->closeEarlierVatBooks($company, $month);
        $capital = $profile['company']['capital'];
        $this->manualEntry($company, $this->bookkeeper, 'capital', $month->toDateString(), 'opening', 'Saldo inicial: aporte de capital depositado en banco', 'APERTURA',
            [['account_id' => $this->account('1102'), 'debit' => $capital, 'credit' => '0'], ['account_id' => $this->account('3101'), 'debit' => '0', 'credit' => $capital]]);
        $customers = $this->customers($company, $profile['customers']);
        $suppliers = $this->suppliers($company, $profile['suppliers']);
        $bank = $this->bankAccount($company, $profile['company']['bank'], $capital, $month);
        $cash = array_values(array_filter($profile['sales'], fn (array $sale): bool => $sale[3]))[0] ?? $profile['sales'][0];
        if (($date = $this->day($month, 1)) !== null) {
            $lines = array_map(fn (array $line): array => [$line[0], $line[1], $line[2], $line[3], null], $cash[2]);
            $invoice = $this->sale($company, $customers[$cash[0]], $date, $lines, $month->format('ym').'01', $profile['company']['series']);
            $this->collect($company, $bank, $invoice, $date, 1.0, true);
            [$supplier, , $account, $treatment, $description, $amount] = $profile['purchases'][0];
            $purchaseAccount = $account === '1201' ? '5103' : $account;
            $this->purchase($company, $suppliers[$supplier], $date, $purchaseAccount, $treatment, $description, $amount, $month->format('ym').'01');
        }
        $this->postDrafts($company);
    }

    /**
     * Quien administra la firma: la Administradora General una vez incorporada (desde entonces el Director solo
     * supervisa y el sistema le niega las acciones de escritura), o el propietario mientras se instala la firma.
     */
    private function admin(): User
    {
        $email = $this->catalogMember('admin-general')['email'].'@'.PlanCorporativoDemoCatalog::DOMAIN;
        $user = User::query()->where('email', $email)->first();
        if ($user !== null && app(WorkAuthorizationService::class)->can($this->organization, $user, 'office.manage')
            && DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return $user;
        }

        return $this->owner;
    }

    /** La persona de la sede de la empresa que ocupa el puesto indicado (supervisor, contador, auxiliar, general, fiscal). */
    private function staff(stdClass $company, string $role): User
    {
        $office = (string) DB::table('offices')->where('id', $company->office_id)->value('code');

        return $this->team[PlanCorporativoDemoCatalog::officeStaff($office)[$role]] ?? $this->owner;
    }

    /** @return array<string, User> El equipo de la sede de la empresa por puesto. */
    private function staffOf(stdClass $company): array
    {
        $staff = [];
        foreach (['supervisor', 'accountant', 'assistant', 'general', 'tax'] as $role) {
            $staff[$role] = $this->staff($company, $role);
        }

        return $staff;
    }

    /** Quien contabiliza: el Contador General de la sede de la empresa (o el propietario si la sede no lo tuviera). */
    private function posterOf(stdClass $company): User
    {
        return $this->generalOf($company) ?? $this->owner;
    }

    private function generalOf(stdClass $company): ?User
    {
        $office = (string) DB::table('offices')->where('id', $company->office_id)->value('code');
        $key = PlanCorporativoDemoCatalog::officeStaff($office)['general'];

        return $this->team[$key] ?? null;
    }

    /**
     * Revisión del período con los servicios reales: auxiliar → Contador Encargado → (en sedes de doble nivel) Contador
     * General → Supervisor (fiscal y cierre). El primer mes queda cerrado; el mes anterior, en una etapa distinta.
     *
     * @param  list<CarbonImmutable>  $months
     */
    private function periodReviews(stdClass $company, int $position, array $months): void
    {
        $this->attempt($company, 'la revisión de '.$months[0]->format('m/Y'), fn () => $this->reviewPeriod($company, $months[0], $position === 3 ? 'ready' : 'closed'));
        $stages = ['submitted', 'changes', 'first_level', 'fiscal_pending'];
        $this->attempt($company, 'la revisión de '.$months[1]->format('m/Y'), fn () => $this->reviewPeriod($company, $months[1], $stages[$position % count($stages)]));
    }

    private function reviewPeriod(stdClass $company, CarbonImmutable $month, string $stage): void
    {
        $period = DB::table('accounting_periods')->where('company_id', $company->id)->where('starts_on', $month->toDateString())->first();
        if ($period === null || $period->closed_at !== null
            || DB::table('company_period_reviews')->where('company_id', $company->id)->where('accounting_period_id', $period->id)->whereNull('deleted_at')->exists()) {
            return;
        }
        $reviews = app(CompanyPeriodReviewService::class);
        $assistant = $this->assistantOf($company);
        $accountant = $this->bookkeeperOf($company);
        $supervisor = $this->supervisorOf($company);
        $double = DB::table('offices')->where('id', $company->office_id)->value('review_workflow') === 'double_level';
        $when = $month->addMonth()->setDay(9);
        $reviewId = 0;
        $this->at($when->setTime(9, 0), function () use ($reviews, $company, $period, $assistant, $accountant, &$reviewId): void {
            $reviewId = $reviews->submit($this->organization, $assistant, (int) $company->id, (int) $period->id, $accountant->id);
        });
        if ($stage === 'submitted') {
            return;
        }
        $this->at($when->setTime(11, 0), fn () => $reviews->start($this->organization, $accountant, $reviewId));
        if ($stage === 'changes') {
            $this->at($when->setTime(15, 0), fn () => $reviews->requestChanges($this->organization, $accountant, $reviewId,
                'Falta el soporte de dos compras de combustible y la conciliación de proveedores no cuadra con el Mayor.'));

            return;
        }
        $this->at($when->addDay()->setTime(10, 0), fn () => $reviews->approveFirstLevel($this->organization, $accountant, $reviewId, 'Libros completos y conciliados.'));
        if ($stage === 'first_level' && $double) {
            return;
        }
        if ($double) {
            $this->at($when->addDays(2)->setTime(10, 0), fn () => $reviews->approveUpper($this->organization, $this->generalOf($company) ?? $this->owner, $reviewId, 'Revisión superior sin observaciones.'));
        }
        if (in_array($stage, ['first_level', 'fiscal_pending'], true)) {
            return;
        }
        $this->at($when->addDays(3)->setTime(10, 0), fn () => $reviews->approveFiscal($this->organization, $supervisor, $reviewId, 'IVA, ISR y FEL revisados.'));
        if ($stage === 'ready') {
            return;
        }
        $this->at($when->addDays(3)->setTime(16, 0), fn () => app(SupervisorService::class)->closePeriod($this->organization, $supervisor, (int) $company->id, (int) $period->id));
    }

    /**
     * Portal del cliente: su contacto (sin asiento), una solicitud respondida y aceptada, otra pendiente, documentos subidos
     * y revisados, una conversación, una autorización aprobada y otra pendiente, y un reporte preparado por el contador,
     * aprobado y publicado por el Director.
     *
     * @param  Profile  $profile
     * @param  list<CarbonImmutable>  $months
     */
    private function clientPortal(stdClass $company, array $profile, array $months): void
    {
        $name = $profile['company']['contact'];
        $email = Str::slug($name, '.').'.'.Str::lower($profile['company']['series']).'@'.PlanCorporativoDemoCatalog::CLIENT_DOMAIN;
        $client = User::query()->where('email', $email)->first();
        if ($client === null || ! DB::table('client_company_access')->where('company_id', $company->id)->where('user_id', $client->id)->exists()) {
            app(ClientAccessAdminService::class)->createContact($this->member, $this->owner, ['name' => $name, 'email' => $email, 'company_id' => (int) $company->id, 'contact_role' => 'owner_contact']);
            $client = User::query()->where('email', $email)->firstOrFail();
            $client->forceFill(['password' => $this->newPassword($email, $name)])->save();
        }
        $assistant = $this->assistantOf($company);
        $accountant = $this->bookkeeperOf($company);
        $requests = app(ClientRequestService::class);
        $documents = app(ClientDocumentService::class);
        $period = SpanishMonth::label($months[1]);

        $title = 'Estados de cuenta bancarios de '.mb_strtolower($period);
        if (! DB::table('client_requests')->where('company_id', $company->id)->where('title', $title)->exists()) {
            $requestId = $requests->create($this->member, $assistant, ['company_id' => (int) $company->id, 'type' => 'document', 'title' => $title,
                'description' => 'Por favor sube el estado de cuenta en PDF de todas las cuentas de la empresa.', 'document_type' => 'bank_statements', 'period_label' => $period,
                'requested_from_user_id' => $client->id, 'due_date' => $months[2]->setDay(5)->toDateString()]);
            $requests->respond($this->organization, $client, $requestId, 'Adjunto el estado de cuenta del mes.', [UploadedFile::fake()->createWithContent('estado-de-cuenta.pdf',
                $this->pdf(['Estado de cuenta bancario - documento de demostracion', 'Empresa: '.Str::ascii((string) $company->name), 'Periodo: '.Str::ascii($period)]))]);
            $requests->review($this->organization, $assistant, $requestId, 'accept', 'Recibido completo.');
            $requests->create($this->member, $assistant, ['company_id' => (int) $company->id, 'type' => 'information', 'title' => 'Contratos de arrendamiento vigentes',
                'description' => 'Necesitamos copia de los contratos para respaldar el gasto de alquiler.', 'requested_from_user_id' => $client->id, 'due_date' => now()->addDays(4)->toDateString()]);
        }
        if (! DB::table('client_documents')->where('company_id', $company->id)->where('title', 'Facturas de compra del mes')->exists()) {
            $accepted = $documents->upload($this->organization, $client, (int) $company->id, UploadedFile::fake()->createWithContent('facturas-compra.pdf',
                $this->pdf(['Facturas de compra - documento de demostracion', 'Empresa: '.Str::ascii((string) $company->name)])), 'purchases', 'Facturas de compra del mes', $period);
            $documents->review($this->organization, $assistant, $accepted, 'accept', 'Facturas recibidas y registradas.', null);
            $documents->upload($this->organization, $client, (int) $company->id, UploadedFile::fake()->createWithContent('planilla-igss.pdf',
                $this->pdf(['Planilla del IGSS - documento de demostracion', 'Empresa: '.Str::ascii((string) $company->name)])), 'payroll', 'Planilla del IGSS del mes', $period);
        }
        if (! DB::table('client_conversations')->where('company_id', $company->id)->exists()) {
            $messages = app(ClientMessageService::class);
            $conversation = $messages->startByClient($this->organization, $client, (int) $company->id, 'Consulta sobre el IVA del mes', 'Buenos días, ¿cuánto debemos pagar de IVA este mes y cuál es la fecha límite?');
            $messages->reply($this->organization, $accountant, $conversation, 'Buenos días. El IVA del mes y su fecha de pago aparecen en Obligaciones; le enviaremos el detalle al cerrar el libro.');
        }
        if (! DB::table('client_approval_requests')->where('company_id', $company->id)->exists()) {
            $approval = $requests->requestApproval($this->member, $accountant, ['company_id' => (int) $company->id, 'type' => 'information_acceptance',
                'title' => 'Aceptación de la información contable de '.mb_strtolower(SpanishMonth::label($months[0])), 'description' => 'Revise los estados publicados y confirme su conformidad.',
                'requested_from_user_id' => $client->id, 'due_date' => now()->addDays(7)->toDateString()]);
            $requests->respondApproval($this->organization, $client, $approval, 'approved', 'Conforme con la información.');
            $requests->requestApproval($this->member, $accountant, ['company_id' => (int) $company->id, 'type' => 'fiscal_confirmation',
                'title' => 'Confirmación del IVA por pagar de '.mb_strtolower($period), 'requested_from_user_id' => $client->id, 'due_date' => now()->addDays(5)->toDateString()]);
        }
        if (! DB::table('published_reports')->where('company_id', $company->id)->exists()) {
            $reports = app(PublishedReportService::class);
            $reportId = $reports->prepare($this->member, $accountant, (int) $company->id, 'income_statement', $months[0]->toDateString(), $months[0]->endOfMonth()->toDateString(), null);
            $reports->action($this->member, $accountant, $reportId, 'submit', null);
            // Aprueba y publica el Director (el Contador General no está asignado a cada empresa): nunca quien lo preparó.
            $reports->action($this->member, $this->owner, $reportId, 'approve', null);
            $reports->action($this->member, $this->owner, $reportId, 'publish', null);
        }
    }

    /** Incidencia abierta por el supervisor de la sede, con el contador encargado como responsable. */
    private function incident(stdClass $company): void
    {
        $types = [['bank_reconciliation', 'medium', 'Depósito sin identificar en el estado de cuenta', 'El banco reporta un depósito sin referencia; confirmar con el cliente a qué factura corresponde.'],
            ['document', 'high', 'Facturas de compra pendientes de recibir', 'El cliente no ha entregado facturas de proveedores del mes anterior; sin ellas no se completa el crédito fiscal.'],
            ['accounting', 'medium', 'Diferencia entre auxiliar de clientes y Mayor', 'La antigüedad de saldos no cuadra con la cuenta 1103 por un cobro registrado sin factura.']];
        [$type, $severity, $title, $description] = $types[(int) $company->id % count($types)];
        app(OperationalIncidentService::class)->create($this->member, $this->supervisorOf($company), ['office_id' => (int) $company->office_id, 'company_id' => (int) $company->id,
            'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')->orderByDesc('starts_on')->value('id'),
            'type' => $type, 'severity' => $severity, 'title' => $title, 'description' => $description, 'responsible_id' => $this->bookkeeperOf($company)->id,
            'blocks_period' => false, 'automation_key' => 'plan-corporativo-demo:incident:'.$company->id]);
    }

    /**
     * Los tres grupos empresariales con su controladora y subsidiarias (participaciones del 100 % y parciales). El
     * consolidado lo calcula la aplicación al consultarlo; aquí solo se registra la estructura.
     *
     * @param  list<array{0: stdClass, 1: Profile}>  $companies
     */
    private function consolidationGroups(array $companies): void
    {
        $byName = [];
        foreach ($companies as [$company]) {
            $byName[(string) $company->name] = (int) $company->id;
        }
        foreach (PlanCorporativoDemoCatalog::groups() as $group) {
            if (DB::table('company_groups')->where('organization_id', $this->organization->id)->where('name', $group['name'])->exists()) {
                continue;
            }
            $members = array_map(fn (array $member): array => ['company_id' => $byName[$member[0]], 'ownership_bp' => $member[1]], $group['members']);
            app(ConsolidationService::class)->save($this->member, $this->owner, null, $group['name'], $byName[$group['parent']], $members, null, array_values($byName));
        }
    }

    /**
     * Destinos de webhooks de demostración (dominios .test que no resuelven, y un servidor simulado en este proceso): el ERP
     * de un cliente suscrito a sus eventos, un sistema de inteligencia de negocios que hoy falla (reintentos) y uno
     * desactivado. El secreto se cifra en la base de datos; el valor en claro solo existe al crearlo y no se guarda.
     *
     * @param  list<array{0: stdClass, 1: Profile}>  $companies
     */
    private function webhookEndpoints(array $companies): void
    {
        $service = app(WebhookEndpointService::class);
        $erp = null;
        foreach ($companies as [$company]) {
            if ($company->name === 'Distribuidora El Progreso, S.A.') {
                $erp = (int) $company->id;
            }
        }
        $endpoints = [
            ['ERP de Distribuidora El Progreso', 'https://erp.distribuidoraelprogreso.test/atlantia/webhooks', ['customer.created', 'sales_invoice.created', 'sales_invoice.issued', 'payment.received',
                'purchase_document.created', 'purchase_document.approved', 'accounting_period.closed', 'report.published', 'client_document.received'], false],
            ['Tablero de indicadores de la firma', 'https://bi.grupocontableatlantico.test/eventos', ['accounting_period.closed', 'report.published'], false],
            ['Integración anterior (en desuso)', 'https://legado.grupocontableatlantico.test/hooks', ['sales_invoice.issued'], true],
        ];
        foreach ($endpoints as [$name, $url, $events, $disabled]) {
            // El ERP del cliente solo recibe los eventos de su propia empresa; los sistemas de la firma, los de todas.
            $scope = str_contains($url, 'erp.') && $erp !== null ? [$erp] : null;
            if (DB::table('webhook_endpoints')->where('organization_id', $this->organization->id)->where('url', $url)->exists()) {
                continue;
            }
            $created = $service->create($this->member, $this->admin(), $name, $url, $events, $scope);
            if ($disabled) {
                $service->action($this->member, $this->admin(), $created['id'], 'disable');
            }
        }
    }

    /** Entrega, con el servidor simulado, las notificaciones generadas por las operaciones de la demostración y un ping de prueba. */
    private function deliverWebhooks(): void
    {
        $service = app(WebhookDeliveryService::class);
        $endpointIds = DB::table('webhook_endpoints')->where('organization_id', $this->organization->id)->pluck('id');
        $pending = DB::table('webhook_deliveries')->whereIn('webhook_endpoint_id', $endpointIds)->where('status', 'pending')->orderBy('id')->limit(300)->pluck('id');
        foreach ($pending as $id) {
            $service->deliver((int) $id, true);
        }
        $first = DB::table('webhook_endpoints')->where('organization_id', $this->organization->id)->where('status', 'active')->orderBy('id')->value('id');
        if ($first !== null) {
            app(WebhookEndpointService::class)->test($this->member, $this->admin(), (int) $first);
        }
    }

    /**
     * Llaves de API y llamadas reales a la API v1 dentro de este proceso (nunca por la red): una llave del ERP de un cliente
     * limitada a su empresa, una de consulta para toda la firma, una revocada y una vencida. El valor en claro de cada llave
     * solo existe en memoria mientras corre el seeder; en la base de datos queda su huella SHA-256.
     *
     * @param  list<array{0: stdClass, 1: Profile}>  $companies
     */
    private function apiScenarios(array $companies): void
    {
        $tokens = app(ApiTokenService::class);
        $erp = null;
        $other = null;
        foreach ($companies as [$company]) {
            if ($company->name === 'Distribuidora El Progreso, S.A.') {
                $erp = $company;
            } elseif ($other === null && $company->name === 'Inversiones Santo Tomás, S.A.') {
                $other = $company;
            }
        }
        if ($erp === null || $other === null || DB::table('api_tokens')->where('organization_id', $this->organization->id)->exists()) {
            return;
        }
        $customer = (int) DB::table('sales_customers')->where('company_id', $erp->id)->where('tax_id', '<>', 'CF')->orderBy('id')->value('id');
        $plain = [];
        $this->attempt($erp, 'las llaves de API', function () use ($tokens, $erp, &$plain): void {
            $plain['erp'] = $tokens->create($this->member, $this->owner, 'ERP de Distribuidora El Progreso', ['companies:read', 'customers:read', 'customers:write', 'sales:read', 'sales:write',
                'receivables:read', 'accounts:read', 'reports:read'], [(int) $erp->id], now()->addYear()->toDateString())['token'];
            $plain['bi'] = $tokens->create($this->member, $this->owner, 'Tablero de indicadores (solo lectura)', ['companies:read', 'accounts:read', 'reports:read'], null, null)['token'];
            $revoked = $tokens->create($this->member, $this->owner, 'Llave del proveedor anterior', ['companies:read'], null, null);
            $plain['revoked'] = $revoked['token'];
            $tokens->revoke($this->member, $this->owner, $revoked['id']);
            $this->at(CarbonImmutable::now()->subDays(45), function () use ($tokens, &$plain): void {
                $plain['expired'] = $tokens->create($this->member, $this->owner, 'Llave de la auditoría externa 2026', ['companies:read', 'reports:read'], null, now()->addDays(30)->toDateString())['token'];
            });
        });
        if (! isset($plain['erp'], $plain['bi'], $plain['revoked'], $plain['expired'])) {
            return;
        }
        $base = '/api/v1/companies/'.$erp->public_id;
        $range = '?from='.CarbonImmutable::now()->startOfMonth()->subMonths(2)->toDateString().'&to='.CarbonImmutable::today()->toDateString();
        $calls = [
            [$plain['erp'], 'GET', '/api/v1/companies', []],
            [$plain['erp'], 'GET', $base.'/accounts', []],
            [$plain['erp'], 'GET', $base.'/customers', []],
            [$plain['erp'], 'GET', $base.'/reports/trial-balance'.$range, []],
            [$plain['erp'], 'POST', $base.'/customers', ['name' => 'Abarrotería La Fe de Puerto Barrios', 'tax_id' => '8274651-3', 'email' => 'compras@abarroterialafe.test', 'payment_terms_days' => 15]],
            [$plain['erp'], 'POST', $base.'/sales-invoices', ['request_id' => $this->uuid($erp, 'api:sale:1'), 'customer_id' => $customer, 'issued_on' => now()->toDateString(),
                'lines' => [['description' => 'Pedido recibido desde el ERP del cliente', 'quantity' => 12, 'unit_price' => '118.00', 'tax_treatment' => 'goods']]]],
            // La llave del ERP no ve otras empresas (404, sin revelar si existen) y la de solo lectura no puede consultar clientes (403).
            [$plain['erp'], 'GET', '/api/v1/companies/'.$other->public_id.'/accounts', []],
            [$plain['bi'], 'GET', '/api/v1/companies/'.$other->public_id.'/reports/income-statement'.$range, []],
            [$plain['bi'], 'GET', '/api/v1/companies/'.$other->public_id.'/customers', []],
            [$plain['revoked'], 'GET', '/api/v1/companies', []],
            [$plain['expired'], 'GET', '/api/v1/companies', []],
        ];
        $kernel = app(HttpKernel::class);
        $tenancy = app(TenantDatabaseContext::class);
        foreach ($calls as [$token, $method, $uri, $body]) {
            $state = $tenancy->state();
            try {
                $request = Request::create($uri, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
                    $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $this->apiResults[] = $method.' '.str_replace([(string) $erp->public_id, (string) $other->public_id], ['{empresa}', '{otra-empresa}'], strtok($uri, '?')).' → '.$response->getStatusCode();
            } catch (Throwable $exception) {
                $this->warn('API: '.$method.' '.$uri.' no se completó. '.$exception->getMessage());
            } finally {
                $tenancy->restore($state);
            }
        }
        unset($plain);
    }

    /** Capacitación del equipo (incluida en el plan): cursos, inscripciones con avance, certificaciones y necesidades detectadas. */
    private function training(): void
    {
        if (DB::table('training_courses')->where('organization_id', $this->organization->id)->exists()) {
            return;
        }
        $service = app(TrainingService::class);
        $assistants = array_map(fn (string $code): int => $this->team['assistant-'.strtolower(substr($code, 0, 3))]->id, array_keys($this->offices));
        $accountants = array_map(fn (string $code): int => $this->team['accountant-'.strtolower(substr($code, 0, 3))]->id, array_keys($this->offices));
        $courses = [
            ['Actualización de la Ley del IVA y el régimen FEL', 'Fiscal', 'virtual', -20, 'completed', $accountants],
            ['Cierre contable mensual con Atlantia Finance', 'Contabilidad', 'híbrido', -5, 'in_progress', $assistants],
            ['Cálculo de planilla, IGSS, IRTRA e INTECAP', 'Nómina', 'presencial', 15, 'scheduled', [$this->team['payroll']->id]],
        ];
        foreach ($courses as [$title, $area, $modality, $days, $status, $people]) {
            $courseId = $service->createCourse($this->member, $this->owner, ['title' => $title, 'area' => $area, 'modality' => $modality, 'starts_on' => now()->addDays($days)->toDateString(),
                'duration_hours' => 8, 'capacity' => 20, 'status' => $status, 'description' => 'Curso interno de la firma para el equipo de las sedes.']);
            $service->enroll($this->member, $this->owner, $courseId, $people);
        }
        foreach ($accountants as $index => $userId) {
            if ($index < 4) {
                $service->createCertification($this->member, $this->owner, ['user_id' => $userId, 'name' => 'Actualización de la Ley del IVA y el régimen FEL', 'issuer' => 'Grupo Contable Atlántico Norte',
                    'issued_on' => now()->subDays(15)->toDateString(), 'generated' => true]);
            }
        }
        $service->createNeed($this->member, $this->owner, ['area' => 'Normativas', 'topic' => 'Normas Internacionales de Información Financiera para PYMES', 'priority' => 'high',
            'objective' => 'Preparar a los contadores generales para la consolidación de grupos empresariales.', 'participants' => [$this->team['general-1']->id, $this->team['general-2']->id], 'target_date' => now()->addMonths(2)->toDateString()]);
    }

    /**
     * Ejecuta una acción de controlador de la oficina (sin empresa en la ruta) como el usuario indicado.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, int|string>  $parameters
     */
    private function actOn(User $user, string $controller, string $action, array $input, array $parameters = []): void
    {
        $request = Request::create('/', 'POST', $input);
        $request->setUserResolver(fn (): User => $user);
        Auth::setUser($user);
        app()->instance('request', $request);
        app()->call(app($controller)->{$action}(...), ['request' => $request, ...$parameters]);
    }

    /** Intereses que paga el banco a fin del mes anterior: Tesorería los clasifica y quedan conciliados con su asiento. */
    private function bankInterest(stdClass $company, stdClass $bank, CarbonImmutable $month): void
    {
        $reference = 'INT-'.$month->format('Ym');
        $this->bankMovement($company, $bank, $month->endOfMonth()->toDateString(), $reference, 'Intereses ganados en cuenta monetaria', 'inflow', '18.75');
        $movement = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->first();
        if ($movement !== null && $movement->reconciliation_status !== 'matched') {
            $this->at($month->endOfMonth()->setTime(18, 5), fn () => app(TreasuryBankService::class)->classify($this->organization, $this->team['treasury'], (int) $movement->id, 'interest'));
        }
    }

    /**
     * Constancia de retención de IVA que entrega un cliente agente retenedor sobre una factura del mes en curso, a la tasa
     * sugerida por el sistema (WithholdingService::SUGGESTED_RATES) sobre el IVA de la factura.
     */
    private function withholding(stdClass $company, CarbonImmutable $month): void
    {
        $invoice = DB::table('sales_invoices')->where('company_id', $company->id)->where('status', 'issued')->where('issued_on', '>=', $month->toDateString())
            ->where('customer_tax_id', '<>', 'CF')->where('vat_cents', '>', 0)->orderByDesc('issued_on')->orderByDesc('id')->first();
        if ($invoice === null) {
            return;
        }
        $requestId = $this->uuid($company, 'withholding:'.$invoice->public_id);
        $amount = intdiv((int) $invoice->vat_cents * WithholdingService::SUGGESTED_RATES['iva'], 10000);
        if ($amount <= 0 || DB::table('sales_receipts')->where('public_id', $requestId)->exists()) {
            return;
        }
        $date = $this->paidOn((string) $invoice->issued_on, 3);
        $this->at(CarbonImmutable::parse($date)->setTime(11, 0), fn () => $this->act($this->team['receivables'], SalesController::class, 'withholdingReceived', ['request_id' => $requestId,
            'kind' => 'iva', 'amount' => $this->decimal($amount), 'certificate_number' => 'RET-DEMO-'.$invoice->fiscal_number, 'certificate_date' => $date], $company, ['invoice' => (string) $invoice->public_id]));
    }

    private function subscription(Organization $organization): void
    {
        [$priceId, $versionId] = app(TenantDatabaseContext::class)->runAsSystem(fn (): array => [
            (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', self::PLAN)
                ->where('plan_prices.billing_period', 'monthly')->where('plan_prices.currency_code', 'GTQ')->value('plan_prices.id'),
            DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')->where('plans.code', self::PLAN)
                ->where('plan_versions.status', 'published')->orderByDesc('plan_versions.version')->value('plan_versions.id'),
        ]);
        if ($priceId === 0) {
            throw new RuntimeException('No existe el precio mensual del plan Profesional. Ejecuta PlanCatalogSeeder.');
        }
        $subscription = Subscription::query()->firstOrNew(['organization_id' => $organization->id, 'provider' => 'manual', 'provider_subscription_id' => 'demo-plan-profesional-'.$organization->id]);
        $subscription->fill(['provider_customer_id' => 'demo-plan-profesional-'.$organization->id, 'status' => 'active', 'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->endOfMonth(), 'cancel_at_period_end' => false])->save();
        if ($versionId !== null) {
            DB::table('subscriptions')->where('id', $subscription->id)->update(['plan_version_id' => (int) $versionId]);
        }
        SubscriptionItem::query()->updateOrCreate(['subscription_id' => $subscription->id, 'item_type' => 'base_plan'],
            ['plan_price_id' => $priceId, 'quantity' => 1, 'provider_item_id' => 'demo-plan-profesional-item-'.$organization->id]);
    }

    private function assignRole(int $userId, string $roleCode, ?int $officeId): void
    {
        $roleId = (int) DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('code', $roleCode)->whereNull('deleted_at')->value('id');
        if ($roleId === 0) {
            throw new RuntimeException("El rol {$roleCode} no existe en la organización.");
        }
        $exists = DB::table('user_role_assignments')->where('organization_id', $this->organization->id)->where('user_id', $userId)->where('workspace_role_id', $roleId)
            ->where(fn ($query) => $officeId === null ? $query->whereNull('office_id') : $query->where('office_id', $officeId))->exists();
        if (! $exists) {
            DB::table('user_role_assignments')->insert(['organization_id' => $this->organization->id, 'user_id' => $userId, 'workspace_role_id' => $roleId, 'office_id' => $officeId,
                'assigned_by' => $this->owner->id, 'status' => 'active', 'starts_at' => now()->subMonths(10), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function responsible(stdClass $company, int $officeId, string $role, User $user): void
    {
        $assigned = DB::table('company_assignments')->where('company_id', $company->id)->where('user_id', $user->id)->where('assignment_role', $role)
            ->where('status', 'active')->whereNull('deleted_at')->exists();
        if ($assigned) {
            return;
        }
        $this->attempt($company, 'la asignación de '.$user->name, fn () => app(CompanyAssignmentService::class)->assign($this->member, $this->owner, $officeId, (int) $company->id,
            $user->id, $role, true, ['source' => 'plan_corporativo_demo'], true));
    }

    private function scaled(string $amount, float $scale): string
    {
        return number_format(round((float) $amount * $scale, 2), 2, '.', '');
    }

    /**
     * Tres meses de la empresa: apertura (con inventario inicial cuando lo maneja), activo fijo, ventas y compras al crédito
     * y al contado, cobros, pagos, banco, kardex, producción, toma física, planilla, depreciación, notas de crédito,
     * conciliación y libros de IVA. Los cierres se hacen después, con la revisión del período.
     *
     * @param  Profile  $profile
     * @param  list<CarbonImmutable>  $months
     */
    private function books(stdClass $company, array $profile, array $months): void
    {
        $data = $profile['company'];
        $this->bookkeeper = $this->bookkeeperOf($company);
        $this->kardex = [];
        $automation = app(AccountingAutomationService::class);
        $mapping = $automation->ensureMapping((int) $company->id);
        $this->accounts = DB::table('ledger_accounts')->where('company_id', $company->id)->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        if ($mapping !== null && $profile['sector']['revenue'] !== '4101') {
            $automation->updateMapping((int) $company->id, ['cash_account_id' => $mapping->cash_account_id, 'receivable_account_id' => $mapping->receivable_account_id,
                'revenue_account_id' => $this->account($profile['sector']['revenue']), 'payable_account_id' => $mapping->payable_account_id,
                'default_expense_account_id' => $mapping->default_expense_account_id]);
        }

        $this->openPeriods($company, $months);
        $this->closeEarlierVatBooks($company, $months[0]);
        $items = $this->inventoryItems($company, $profile, $months[0]);
        $stockValue = array_sum(array_map(fn (array $item): int => $item['opening_cents'], $items));
        $lines = [['account_id' => $this->account('1102'), 'debit' => $data['capital'], 'credit' => '0']];
        if ($stockValue > 0) {
            $lines[] = ['account_id' => $this->account('1201'), 'debit' => $this->decimal($stockValue), 'credit' => '0'];
        }
        $lines[] = ['account_id' => $this->account('3101'), 'debit' => '0', 'credit' => $this->decimal($this->cents($data['capital']) + $stockValue)];
        $this->manualEntry($company, $this->owner, 'capital', $months[0]->toDateString(), 'opening',
            $stockValue > 0 ? 'Saldos iniciales: capital en bancos e inventario de mercadería' : 'Saldo inicial: aporte de capital depositado en banco', 'APERTURA', $lines);

        $customers = $this->customers($company, $profile['customers']);
        $suppliers = $this->suppliers($company, $profile['suppliers']);
        $bank = $this->bankAccount($company, $data['bank'], $data['capital'], $months[0]);
        $asset = $this->fixedAsset($company, $data, $suppliers, $months);

        foreach ($months as $index => $month) {
            $last = $index === count($months) - 1;
            if ($data['production']) {
                $this->productionOrder($company, $month);
            }
            foreach ($profile['sales'] as $number => [$customer, $day, $saleLines, $cash]) {
                $date = $this->day($month, $day);
                if ($date === null) {
                    continue;
                }
                $invoice = $this->sale($company, $customers[$customer], $date, $saleLines, sprintf('%s%02d', $month->format('ym'), $number + 1), $data['series']);
                foreach ($saleLines as $position => $line) {
                    if ($line[4] !== null) {
                        $this->kardex[] = ['date' => $date, 'order' => 3, 'type' => 'sale', 'item' => $line[4], 'quantity' => $line[1], 'cost' => null,
                            'reference' => 'FAC-'.$invoice->fiscal_number.'-'.($position + 1)];
                    }
                }
                $share = $cash ? 1.0 : ($last ? 0.5 : 1.0);
                $this->collect($company, $bank, $invoice, $cash ? $date : $this->paidOn($date, 6), $share, $cash);
            }
            $creditPurchases = array_keys(array_filter($profile['purchases'], fn (array $purchase): bool => ! $purchase[6]));
            foreach ($profile['purchases'] as $number => [$supplier, $day, $account, $treatment, $description, $amount, $cash, $item, $quantity]) {
                $date = $this->day($month, $day);
                if ($date === null) {
                    continue;
                }
                $document = $this->purchase($company, $suppliers[$supplier], $date, $account, $treatment, $description, $amount, sprintf('%s%02d', $month->format('ym'), $number + 1));
                if ($item !== null && $quantity > 0) {
                    $this->kardex[] = ['date' => $date, 'order' => 1, 'type' => 'purchase', 'item' => $item, 'quantity' => $quantity,
                        'cost' => $this->decimal((int) round($this->cents($amount) * 100 / 112 / $quantity)), 'reference' => 'COMPRA-'.$document->fiscal_number];
                }
                if ($cash) {
                    $this->pay($company, $bank, $document, $date, true, true);
                } elseif (! $last || $day <= 10) {
                    $cashed = ! ($index === count($months) - 2 && $number === end($creditPurchases));
                    $this->pay($company, $bank, $document, $this->paidOn($date, 8), $cashed, false);
                }
            }
            if (! $last) {
                $this->bankFee($company, $bank, $month, $index === 0);
            }
            if ($index === 1) {
                $this->pettyCash($company, $bank, $month);
                $this->bankInterest($company, $bank, $month);
            }
        }
        $this->attempt($company, 'las notas de crédito', fn () => $this->creditNotes($company, $months[2]));
        $this->attempt($company, 'la retención recibida', fn () => $this->withholding($company, $months[2]));
        if ($items !== []) {
            $this->attempt($company, 'el kardex y el costo de ventas', fn () => $this->inventory($company, $items, $months));
        }
        if ($data['payroll']) {
            $this->attempt($company, 'la planilla', fn () => $this->payroll($company, $months));
        }
        if ($asset !== null) {
            foreach (array_slice($months, 0, 2) as $month) {
                $this->attempt($company, 'la depreciación de '.$month->format('m/Y'), fn () => $this->at($month->endOfMonth()->setTime(17, 30),
                    fn () => app(FixedAssetService::class)->depreciate($this->organization, $this->bookkeeper, (int) $company->id, IlluminateCarbon::parse($month->toDateString()))));
            }
        }
        $this->postDrafts($company);
        $this->attempt($company, 'la conciliación bancaria', fn () => $this->at($months[2]->setDay(5)->setTime(12, 0), fn () => $this->reconcile($company, $bank, $months[1])));
        foreach (array_slice($months, 0, -1) as $month) {
            $this->attempt($company, 'el libro de IVA de '.$month->format('m/Y'), fn () => $this->at($month->addMonth()->setDay(8)->setTime(17, 0), fn () => $this->closeVatBook($company, $month)));
        }
    }

    private function bookkeeperOf(stdClass $company): User
    {
        $id = DB::table('company_assignments')->where('company_id', $company->id)->where('assignment_role', 'accountant_in_charge')->where('status', 'active')
            ->whereNull('deleted_at')->orderByDesc('is_primary')->value('user_id');

        return ($id === null ? null : User::query()->whereKey((int) $id)->first()) ?? $this->owner;
    }

    /** Auxiliar de la empresa si tiene; si no, su contador encargado. */
    private function assistantOf(stdClass $company): User
    {
        $id = DB::table('company_assignments')->where('company_id', $company->id)->where('assignment_role', 'accounting_assistant')->where('status', 'active')
            ->whereNull('deleted_at')->value('user_id');

        return ($id === null ? null : User::query()->whereKey((int) $id)->first()) ?? $this->bookkeeperOf($company);
    }

    private function supervisorOf(stdClass $company): User
    {
        $id = DB::table('company_assignments')->where('company_id', $company->id)->where('assignment_role', 'supervisor')->where('status', 'active')
            ->whereNull('deleted_at')->value('user_id');

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

    private function closeEarlierVatBooks(stdClass $company, CarbonImmutable $firstMonth): void
    {
        $month = CarbonImmutable::parse((string) $company->created_at)->startOfMonth()->max($firstMonth->subMonths(4));
        while ($month->lessThan($firstMonth)) {
            $closing = $month;
            $this->attempt($company, 'el libro de IVA de '.$closing->format('m/Y'), fn () => $this->at($closing->addMonth()->setDay(8)->setTime(17, 0), fn () => $this->closeVatBook($company, $closing)));
            $month = $month->addMonth();
        }
    }

    /** @return list<CarbonImmutable> */
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
                $this->act($this->team['receivables'], SalesController::class, 'customer', ['name' => $name, 'tax_id' => $taxId, 'email' => $email, 'payment_terms_days' => $terms], $company);
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
                $this->act($this->team['payables'], PurchaseController::class, 'supplier', ['name' => $name, 'tax_id' => $taxId, 'email' => $email, 'phone' => $phone,
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
            $this->act($this->team['treasury'], BankController::class, 'storeAccount', ['ledger_account_id' => $this->account('1102'), 'bank_name' => $bankName,
                'account_name' => $accountName, 'account_type' => 'checking', 'last_four' => $lastFour, 'bank_balance' => '0.00', 'currency' => 'GTQ'], $company);
            $account = DB::table('bank_accounts')->where('company_id', $company->id)->where('last_four', $lastFour)->firstOrFail();
            // El saldo inicial ya estaba en el banco el día anterior al primer mes que se lleva en Atlantia.
            $this->bankMovement($company, $account, $firstMonth->subDay()->toDateString(), 'DEP-APERTURA', 'Saldo inicial de la cuenta al iniciar en Atlantia', 'inflow', $capital);
        }

        return $account;
    }

    /**
     * Activo fijo de la empresa: la factura del proveedor (a la cuenta de propiedad, planta y equipo) y su ficha en el
     * módulo de activos, que calcula la depreciación de cada mes.
     *
     * @param  Company  $data
     * @param  array<string, int>  $suppliers
     * @param  list<CarbonImmutable>  $months
     */
    private function fixedAsset(stdClass $company, array $data, array $suppliers, array $months): ?stdClass
    {
        if ($data['asset'] === null || ($date = $this->day($months[0], 2)) === null) {
            return null;
        }
        [$name, $category, $total, $life] = $data['asset'];
        $document = $this->purchase($company, $suppliers[self::ASSET_SUPPLIER[0]], $date, '1401', 'goods', 'Compra de '.mb_strtolower($name), $total, $months[0]->format('ym').'AF');
        $code = 'AF-'.$data['series'].'-01';
        if (! DB::table('fixed_assets')->where('company_id', $company->id)->where('code', $code)->exists()) {
            $this->at(CarbonImmutable::parse($date)->setTime(17, 15), fn () => app(FixedAssetService::class)->create($this->organization, $this->bookkeeper, (int) $company->id, [
                'code' => $code, 'name' => $name, 'category' => $category, 'acquired_on' => $date, 'in_service_on' => $date,
                'cost' => $this->decimal((int) round($this->cents($total) * 100 / 112)), 'salvage' => '0.00', 'useful_life_months' => $life, 'method' => 'straight_line', 'cost_center' => null]));
        }

        return $document;
    }

    /** @param list<array{0: string, 1: int, 2: string, 3: string, 4: string|null}> $lines */
    private function sale(stdClass $company, int $customerId, string $date, array $lines, string $number, string $series): stdClass
    {
        $requestId = $this->uuid($company, 'sale:'.$number.':'.$customerId);
        if (! DB::table('sales_invoices')->where('public_id', $requestId)->exists()) {
            // Facturas registradas para la demostración: serie, número y autorización son ficticios, no certificados por la SAT.
            $this->at(CarbonImmutable::parse($date)->setTime(10, 20), fn () => $this->act($this->assistantOf($company), SalesController::class, 'store', ['request_id' => $requestId,
                'customer_id' => $customerId, 'issued_on' => $date, 'fiscal_document_type' => 'FACT', 'fiscal_series' => $series, 'fiscal_number' => $number,
                'fiscal_authorization' => $this->authorization($company, 'sale:'.$number.':'.$customerId),
                'lines' => array_map(fn (array $line): array => ['description' => $line[0], 'quantity' => $line[1], 'unit_price' => $line[2], 'tax_treatment' => $line[3]], $lines)], $company));
        }
        $invoice = DB::table('sales_invoices')->where('public_id', $requestId)->firstOrFail();
        if ($invoice->status === 'draft') {
            $this->at(CarbonImmutable::parse($date)->setTime(10, 35), fn () => $this->act($this->team['receivables'], SalesController::class, 'process', ['action' => 'issue'], $company, ['invoice' => $requestId]));
        }

        return DB::table('sales_invoices')->where('public_id', $requestId)->firstOrFail();
    }

    /** Quien opera Tesorería: registra cobros, prepara y ejecuta pagos y concilia el banco. */
    private function treasurer(): User
    {
        return $this->team['treasury'];
    }

    /**
     * Cobro de una factura como en Tesorería: el depósito llega al estado de cuenta y Tesorería registra el cobro
     * aplicado a la factura y conciliado con ese depósito (el recibo de la factura y su asiento los genera el servicio).
     */
    private function collect(stdClass $company, stdClass $bank, stdClass $invoice, string $date, float $share, bool $cash): void
    {
        $reference = ($cash ? 'EFE-' : 'TRF-').$invoice->fiscal_number;
        if (DB::table('sales_receipts')->where('public_id', $this->uuid($company, 'receipt:'.$invoice->public_id))->exists()
            || DB::table('treasury_receipts')->where('company_id', $company->id)->where('reference', $reference)->exists()) {
            return;
        }
        $amount = (int) round((int) $invoice->total_cents * $share);
        $this->bankMovement($company, $bank, $date, $reference, $cash ? 'Depósito de ventas al contado' : 'Transferencia recibida de '.$invoice->customer_name, 'inflow', $this->decimal($amount));
        $movement = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->where('reconciliation_status', 'pending')->value('id');
        $this->at(CarbonImmutable::parse($date)->setTime(15, 10), fn () => app(TreasuryReceiptService::class)->register($this->organization, $this->treasurer(), (int) $company->id, [
            'bank_account_id' => (int) $bank->id, 'method' => $cash ? 'deposit' : 'transfer', 'amount_cents' => $amount, 'received_on' => $date, 'reference' => $reference,
            'bank_transaction_id' => $movement === null ? null : (int) $movement, 'allocations' => [['sales_invoice_id' => (int) $invoice->id, 'amount_cents' => $amount]]]));
    }

    private function purchase(stdClass $company, int $supplierId, string $date, string $account, string $treatment, string $description, string $amount, string $number): stdClass
    {
        $requestId = $this->uuid($company, 'purchase:'.$number.':'.$supplierId);
        $supplier = DB::table('purchase_suppliers')->where('id', $supplierId)->firstOrFail();
        if (! DB::table('purchase_documents')->where('public_id', $requestId)->exists()) {
            $isPurchase = $treatment === 'goods' && (str_starts_with($account, '51') || str_starts_with($account, '12') || str_starts_with($account, '14'));
            $this->at(CarbonImmutable::parse($date)->setTime(11, 40), fn () => $this->act($this->assistantOf($company), PurchaseController::class, 'store', ['request_id' => $requestId,
                'supplier_id' => $supplierId, 'document_type' => $isPurchase ? 'purchase' : 'expense', 'external_reference' => 'FAC-'.$number,
                'document_date' => $date, 'due_on' => CarbonImmutable::parse($date)->addDays((int) ($supplier->payment_terms_days ?? 15))->toDateString(),
                'fiscal_document_type' => 'FACT', 'fiscal_series' => $this->supplierSeries($supplier), 'fiscal_number' => $number,
                'fiscal_authorization' => $this->authorization($company, 'purchase:'.$number.':'.$supplierId),
                'lines' => [['account_id' => $this->account($account), 'description' => $description, 'quantity' => 1, 'unit_price' => $amount, 'tax_treatment' => $treatment]]], $company));
        }
        $document = DB::table('purchase_documents')->where('public_id', $requestId)->firstOrFail();
        if ($document->status === 'draft') {
            $this->at(CarbonImmutable::parse($date)->setTime(17, 0), fn () => $this->act($this->team['payables'], PurchaseController::class, 'process', ['action' => 'approve'], $company, ['document' => $requestId]));
        }

        return DB::table('purchase_documents')->where('public_id', $requestId)->firstOrFail();
    }

    private function supplierSeries(stdClass $supplier): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $supplier->name)) ?? 'PROV', 0, 4));
    }

    /**
     * Pago a proveedor como en Tesorería: Tesorería prepara la orden de pago, el Contador Encargado la aprueba (quien
     * prepara no aprueba), Tesorería la ejecuta con su comprobante y, cuando el débito aparece en el banco, lo concilia.
     */
    private function pay(stdClass $company, stdClass $bank, stdClass $document, string $date, bool $cashed, bool $transfer): void
    {
        if (DB::table('purchase_payments')->where('purchase_document_id', $document->id)->exists()) {
            return;
        }
        $reference = ($transfer ? 'TRF-' : 'CHQ-').str_pad((string) $document->number, 5, '0', STR_PAD_LEFT);
        $payments = app(TreasuryPaymentService::class);
        $treasurer = $this->treasurer();
        $instruction = 0;
        $this->at(CarbonImmutable::parse($date)->setTime(14, 0), function () use ($payments, $treasurer, $company, $bank, $document, $transfer, &$instruction): void {
            $instruction = $payments->prepare($this->organization, $treasurer, (int) $company->id, ['bank_account_id' => (int) $bank->id, 'method' => $transfer ? 'transfer' : 'check',
                'concept' => 'Pago de la factura '.$document->number.' de '.$document->supplier_name, 'priority' => 'normal', 'scheduled_on' => null,
                'items' => [['purchase_document_id' => (int) $document->id, 'amount_cents' => (int) $document->total_cents]]]);
        });
        $this->at(CarbonImmutable::parse($date)->setTime(15, 0), fn () => $payments->approve($this->organization, $this->bookkeeperOf($company), $instruction, 'Factura revisada contra la orden de compra.'));
        $proof = UploadedFile::fake()->createWithContent('comprobante-'.Str::slug($reference).'.pdf', $this->pdf(['Comprobante de pago - documento de demostracion',
            'Empresa: '.Str::ascii((string) $company->name), 'Proveedor: '.Str::ascii((string) $document->supplier_name), 'Referencia: '.$reference, 'Fecha: '.$date]));
        $this->at(CarbonImmutable::parse($date)->setTime(16, 20), fn () => $payments->execute($this->organization, $treasurer, $instruction, $date, $reference, $proof));
        $clearedOn = $cashed ? $date : $this->paidOn(CarbonImmutable::parse($date)->startOfMonth()->addMonth()->toDateString(), 2);
        $this->bankMovement($company, $bank, $clearedOn, $reference, ($transfer ? 'Transferencia pagada a ' : 'Cheque pagado a ').$document->supplier_name, 'outflow', $this->decimal((int) $document->total_cents));
        $movement = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->where('reconciliation_status', 'pending')->value('id');
        $banking = app(TreasuryBankService::class);
        if ($movement !== null && collect($banking->suggestions((int) $movement))->contains(fn (array $row): bool => $row['type'] === 'payment_instructions' && $row['id'] === $instruction)) {
            $this->at(CarbonImmutable::parse($clearedOn)->setTime(17, 30), fn () => $banking->match($this->organization, $treasurer, (int) $movement, 'payment_instructions', $instruction));
        }
    }

    /** Comisión mensual: la del primer mes la clasifica Tesorería (asiento automático y movimiento conciliado); la del segundo queda pendiente. */
    private function bankFee(stdClass $company, stdClass $bank, CarbonImmutable $month, bool $classified): void
    {
        $reference = 'COM-'.$month->format('Ym');
        $this->bankMovement($company, $bank, $month->endOfMonth()->toDateString(), $reference, 'Comisión por manejo de cuenta', 'outflow', self::BANK_FEE);
        $movement = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->first();
        if ($classified && $movement !== null && $movement->reconciliation_status !== 'matched') {
            $this->at($month->endOfMonth()->setTime(18, 0), fn () => app(TreasuryBankService::class)->classify($this->organization, $this->team['treasury'], (int) $movement->id, 'bank_fee'));
        }
    }

    private function pettyCash(stdClass $company, stdClass $bank, CarbonImmutable $month): void
    {
        $date = $this->day($month, 2);
        if ($date === null) {
            return;
        }
        $this->manualEntry($company, $this->bookkeeper, 'petty-cash:'.$month->format('Ym'), $date, 'standard', 'Retiro bancario para constituir el fondo de caja chica', 'RET-'.$month->format('Ym'),
            [['account_id' => $this->account('1101'), 'debit' => self::PETTY_CASH, 'credit' => '0'], ['account_id' => $this->account('1102'), 'debit' => '0', 'credit' => self::PETTY_CASH]]);
        $this->bankMovement($company, $bank, $date, 'RET-'.$month->format('Ym'), 'Retiro de efectivo para caja chica', 'outflow', self::PETTY_CASH);
    }

    private function bankMovement(stdClass $company, stdClass $bank, string $date, string $reference, string $description, string $flow, string $amount): void
    {
        if (DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reference', $reference)->exists()) {
            return;
        }
        $this->act($this->team['treasury'], BankController::class, 'storeTransaction', ['account_id' => $bank->id, 'transaction_date' => $date, 'reference' => $reference,
            'description' => $description, 'flow' => $flow, 'amount' => $amount], $company);
    }

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
                $this->at(CarbonImmutable::parse($issuedOn)->setTime(9, 30), fn () => $this->act($this->team['receivables'], SalesController::class, 'creditNote', ['amount' => $this->decimal($amount),
                    'issued_on' => $issuedOn, 'reason' => 'Descuento comercial del 10 % acordado con el cliente por volumen de compra'], $company, ['invoice' => (string) $invoice->public_id]));
            }
        }
        $document = DB::table('purchase_documents')->where('company_id', $company->id)->where('status', 'approved')->where('document_date', '>=', $from)
            ->whereNotIn('id', DB::table('purchase_payments')->select('purchase_document_id'))->orderBy('document_date')->orderBy('id')->first();
        if ($document !== null && ! DB::table('purchase_credit_notes')->where('purchase_document_id', $document->id)->exists()) {
            $issuedOn = $this->paidOn((string) $document->document_date, 3);
            $this->at(CarbonImmutable::parse($issuedOn)->setTime(10, 0), fn () => $this->act($this->team['payables'], PurchaseController::class, 'creditNote', ['amount' => $this->decimal(intdiv((int) $document->total_cents, 20)),
                'issued_on' => $issuedOn, 'reason' => 'Bonificación del proveedor por mercadería entregada con daños', 'fiscal_series' => 'NC', 'fiscal_number' => (string) $document->fiscal_number],
                $company, ['document' => (string) $document->public_id]));
        }
    }

    /**
     * Productos del inventario de la empresa (solo en sectores que lo manejan) y su existencia inicial al primer día.
     *
     * @param  Profile  $profile
     * @return array<string, array{id: int, opening_cents: int}>
     */
    private function inventoryItems(stdClass $company, array $profile, CarbonImmutable $firstMonth): array
    {
        $items = [];
        $service = app(InventoryService::class);
        foreach ($profile['sector']['items'] as [$code, $name, $unit, $cost, $opening]) {
            $id = DB::table('inventory_items')->where('company_id', $company->id)->where('code', $code)->value('id');
            if ($id === null) {
                $id = $service->createItem($this->organization, $this->bookkeeper, (int) $company->id, ['code' => $code, 'name' => $name, 'unit' => $unit, 'min_stock' => (string) intdiv($opening, 4)]);
            }
            $unitCost = $this->scaled($cost, $profile['company']['scale']);
            $items[$code] = ['id' => (int) $id, 'opening_cents' => $this->cents($unitCost) * $opening];
            $this->kardex[] = ['date' => $firstMonth->toDateString(), 'order' => 0, 'type' => 'opening', 'item' => $code, 'quantity' => $opening, 'cost' => $unitCost, 'reference' => 'INV-INICIAL-'.$code];
        }

        return $items;
    }

    /**
     * Kardex en orden cronológico (existencia inicial, compras, producción y ventas), toma física al cierre del mes anterior
     * con un faltante, y el costo de ventas de cada salida en un asiento.
     *
     * @param  array<string, array{id: int, opening_cents: int}>  $items
     * @param  list<CarbonImmutable>  $months
     */
    private function inventory(stdClass $company, array $items, array $months): void
    {
        $service = app(InventoryService::class);
        $events = $this->kardex;
        usort($events, fn (array $a, array $b): int => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);
        $countDate = $months[1]->endOfMonth()->toDateString();
        $counted = false;
        foreach ($events as $event) {
            if (! $counted && $event['date'] > $countDate) {
                $this->physicalCount($company, $items, $countDate);
                $counted = true;
            }
            if ($event['type'] === 'production') {
                foreach ($this->productionPlan as $plan) {
                    if ((int) $plan['company']->id === (int) $company->id && $plan['reference'] === $event['reference']) {
                        $this->runProduction($plan);
                    }
                }

                continue;
            }
            if (! isset($items[$event['item']]) || DB::table('inventory_movements')->where('inventory_item_id', $items[$event['item']]['id'])->where('reference', $event['reference'])->exists()) {
                continue;
            }
            $this->at(CarbonImmutable::parse($event['date'])->setTime(12, 0), fn () => $service->record($this->organization, $this->bookkeeper, (int) $company->id, $items[$event['item']]['id'], [
                'date' => $event['date'], 'type' => $event['type'], 'quantity' => (string) $event['quantity'], 'unit_cost' => $event['cost'], 'reference' => $event['reference'],
                'memo' => $this->memo($event['type'])]));
        }
        if (! $counted) {
            $this->physicalCount($company, $items, $countDate);
        }
        $this->at(CarbonImmutable::today()->setTime(18, 0), fn () => $service->postCosts($this->organization, $this->bookkeeper, (int) $company->id));
    }

    /** @param array<string, array{id: int, opening_cents: int}> $items */
    private function physicalCount(stdClass $company, array $items, string $date): void
    {
        if (CarbonImmutable::parse($date)->isAfter(CarbonImmutable::today()) || DB::table('inventory_counts')->where('company_id', $company->id)->where('count_date', $date)->exists()) {
            return;
        }
        $service = app(InventoryCountService::class);
        $this->at(CarbonImmutable::parse($date)->setTime(17, 0), function () use ($service, $company, $items, $date): void {
            $countId = $service->start($this->organization, $this->bookkeeper, (int) $company->id, $date, 'Toma física de fin de mes realizada por el auxiliar y el contador encargado.');
            $lines = DB::table('inventory_count_lines')->where('inventory_count_id', $countId)->get(['id', 'inventory_item_id', 'system_units']);
            $first = (int) (array_values($items)[0]['id'] ?? 0);
            $counted = [];
            foreach ($lines as $line) {
                $units = (int) $line->system_units;
                // Se encontró un faltante de dos unidades del primer producto (merma); el resto coincide con el kardex.
                $counted[(int) $line->id] = (string) (((int) $line->inventory_item_id === $first ? max(0, $units - 20000) : $units) / 10000);
            }
            $count = $service->find((int) $company->id, $countId);
            $service->record($this->organization, $this->bookkeeper, $count, $counted);
            $service->apply($this->organization, $this->bookkeeper, $service->find((int) $company->id, $countId));
        });
    }

    /** La panificadora produce su pan de molde al inicio de cada mes con su receta estándar. */
    private function productionOrder(stdClass $company, CarbonImmutable $month): void
    {
        $date = $this->day($month, 1);
        if ($date === null) {
            return;
        }
        $product = (int) DB::table('inventory_items')->where('company_id', $company->id)->where('code', 'PAN-MOLDE')->value('id');
        $flour = (int) DB::table('inventory_items')->where('company_id', $company->id)->where('code', 'HARINA-QQ')->value('id');
        $sugar = (int) DB::table('inventory_items')->where('company_id', $company->id)->where('code', 'AZUCAR-QQ')->value('id');
        if ($product === 0 || $flour === 0 || $sugar === 0) {
            return;
        }
        $this->kardex[] = ['date' => $date, 'order' => 2, 'type' => 'production', 'item' => 'PAN-MOLDE', 'quantity' => 400, 'cost' => null, 'reference' => 'OP-'.$month->format('Ym')];
        $this->productionPlan[] = ['company' => $company, 'date' => $date, 'reference' => 'OP-'.$month->format('Ym'), 'product' => $product, 'flour' => $flour, 'sugar' => $sugar];
    }

    /**
     * Planilla mensual con las reglas y tasas de la aplicación: empleados, cálculo y envío por Nómina, aprobación por el
     * Contador Encargado (nunca quien la calculó). La del mes en curso queda calculada, pendiente de enviar.
     *
     * @param  list<CarbonImmutable>  $months
     */
    private function payroll(stdClass $company, array $months): void
    {
        $records = app(PayrollRecordService::class);
        $periods = app(PayrollPeriodService::class);
        $payroller = $this->team['payroll'];
        foreach (PlanMultiOficinaDemoCatalog::employees() as $position => [$code, $first, $last, $title, $department, $salary, $sex]) {
            if (DB::table('payroll_employees')->where('company_id', $company->id)->where('code', $code)->exists()) {
                continue;
            }
            $records->saveEmployee($this->organization, $payroller, (int) $company->id, ['code' => $code, 'first_name' => $first, 'last_name' => $last,
                'dpi' => sprintf('%04d%05d%04d', (int) $company->id % 10000, $position + 1, 1801), 'nit' => null, 'igss_number' => sprintf('%09d', (int) $company->id * 100 + $position + 1),
                'email' => null, 'position' => $title, 'department' => $department, 'hire_date' => CarbonImmutable::now()->subYears(2)->subMonths($position)->startOfMonth()->toDateString(),
                'pay_frequency' => 'monthly', 'igss_affiliated' => true, 'payment_method' => 'transfer', 'bank_name' => 'Banco Industrial', 'bank_account_number' => sprintf('%03d-%06d-%d', 410, (int) $company->id * 10 + $position, 7),
                'bank_account_type' => 'monetaria', 'sex' => $sex, 'birth_date' => CarbonImmutable::now()->subYears(28 + $position * 4)->toDateString(), 'nationality' => 'Guatemalteca',
                'base_salary_cents' => $this->cents($salary), 'incentive_bonus_cents' => 25000]);
        }
        foreach ($months as $index => $month) {
            $code = $month->format('Y-m');
            $period = DB::table('payroll_periods')->where('company_id', $company->id)->where('code', $code)->first();
            $last = $index === count($months) - 1;
            if ($period === null) {
                $this->at($month->setDay(min(20, $month->daysInMonth))->setTime(9, 0), fn () => $periods->open($this->organization, $payroller, (int) $company->id, $month->year, $month->month));
                $period = DB::table('payroll_periods')->where('company_id', $company->id)->where('code', $code)->firstOrFail();
            }
            if ($period->status === 'open') {
                $this->at($month->setDay(min(25, $month->daysInMonth))->setTime(10, 0), fn () => $periods->calculate($this->organization, $payroller, (int) $period->id));
            }
            if ($last) {
                continue;
            }
            $period = DB::table('payroll_periods')->where('id', $period->id)->firstOrFail();
            if ($period->status === 'calculated') {
                $this->at($month->setDay(min(26, $month->daysInMonth))->setTime(11, 0), fn () => $periods->submit($this->organization, $payroller, (int) $period->id));
                $this->at($month->endOfMonth()->setTime(9, 0), fn () => $periods->approve($this->organization, $this->bookkeeperOf($company), (int) $period->id, 'Revisada contra el libro de salarios y las planillas del IGSS.'));
            }
        }
    }

    private function postDrafts(stdClass $company): void
    {
        $accounting = app(AccountingService::class);
        $poster = $this->posterOf($company);
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $company->id)->where('e.status', 'draft')->orderBy('e.entry_date')->orderBy('e.id')->get(['e.*']);
        foreach ($entries as $entry) {
            if (in_array($entry->kind, ['standard', 'adjustment', 'opening'], true) && ! DB::table('journal_entry_documents')->where('journal_entry_id', $entry->id)->exists()) {
                $this->attachSupport($company, $entry);
            }
            $this->at(CarbonImmutable::parse((string) $entry->entry_date)->setTime(18, 30), fn () => $accounting->post((int) $company->id, $entry, $poster));
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

    /** @param array{company: stdClass, date: string, reference: string, product: int, flour: int, sugar: int} $plan */
    private function runProduction(array $plan): void
    {
        $company = $plan['company'];
        if (DB::table('production_orders')->where('company_id', $company->id)->where('reference', $plan['reference'])->exists()) {
            return;
        }
        $this->attempt($company, 'la orden de producción '.$plan['reference'], function () use ($company, $plan): void {
            $service = app(ProductionService::class);
            $recipe = DB::table('bills_of_materials')->where('company_id', $company->id)->where('product_item_id', $plan['product'])->value('id');
            $this->at(CarbonImmutable::parse($plan['date'])->setTime(7, 0), function () use ($service, $company, $plan, &$recipe): void {
                if ($recipe === null) {
                    $recipe = $service->saveRecipe($this->organization, $this->bookkeeper, (int) $company->id, $plan['product'], [
                        ['item_id' => $plan['flour'], 'quantity' => '0.0040', 'standard_cost' => null], ['item_id' => $plan['sugar'], 'quantity' => '0.0010', 'standard_cost' => null]], 150, 100);
                }
                $service->produce($this->organization, $this->bookkeeper, $company, (int) $recipe, '400', $plan['date'], $plan['reference']);
            });
        });
    }

    private function reconcile(stdClass $company, stdClass $bank, CarbonImmutable $month): void
    {
        $asOf = $month->endOfMonth()->toDateString();
        if (DB::table('account_reconciliations')->where('company_id', $company->id)->where('ledger_account_id', $this->account('1102'))->where('as_of', $asOf)->exists()) {
            return;
        }
        $service = app(AccountReconciliationService::class);
        $preparer = $this->team['treasury'];
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
        $service->review($this->organization, $this->bookkeeper, (int) $company->id, $id);
    }

    private function closeVatBook(stdClass $company, CarbonImmutable $month): void
    {
        $books = app(VatBookService::class);
        if ($books->book((int) $company->id, $month->year, $month->month)['status'] !== 'closed') {
            $books->close($this->organization, $this->staff($company, 'tax'), (int) $company->id, $month->year, $month->month);
        }
    }

    /**
     * Partidas del mes en curso en cada estado del flujo, con separación de funciones: el auxiliar prepara, el contador
     * encargado revisa y aprueba, el supervisor rechaza lo que no procede y el Contador General (contabiliza. Un ajuste nunca lo aprueba quien lo creó.
     *
     * @param  Profile  $profile
     */
    private function journalWorkflow(stdClass $company, array $profile, int $position, CarbonImmutable $month): void
    {
        if ($position > 3) {
            return;
        }
        $this->accounts = DB::table('ledger_accounts')->where('company_id', $company->id)->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        $accounting = app(AccountingService::class);
        $preparer = $this->assistantOf($company);
        $accountant = $this->bookkeeperOf($company);
        $approver = $preparer->id === $accountant->id ? $this->owner : $accountant;
        $poster = $this->posterOf($company);
        $line = fn (string $code, string $debit, string $credit): array => ['account_id' => $this->account($code), 'debit' => $debit, 'credit' => $credit];
        $tag = $month->format('Ym');

        if (($date = $this->day($month, 3)) !== null) {
            $entry = $this->manualEntry($company, $preparer, 'flow:provision', $date, 'provision', 'Provisión de honorarios de auditoría externa del mes', 'PROV-'.$tag,
                [$line('5208', '2450.00', '0'), $line('2102', '0', '2450.00')], false);
            if ($entry !== null && $entry->status === 'draft') {
                $accounting->submitForReview($this->organization, $entry, $preparer);
            }
        }
        if (($date = $this->day($month, 4)) !== null) {
            $entry = $this->manualEntry($company, $preparer, 'flow:adjustment', $date, 'adjustment', 'Ajuste por faltante en arqueo de caja chica', 'ARQ-'.$tag,
                [$line('5201', '48.75', '0'), $line('1101', '0', '48.75')]);
            if ($entry !== null && $entry->status === 'draft') {
                $accounting->submitForReview($this->organization, $entry, $preparer);
                $accounting->review($this->organization, $this->refresh($entry), $accountant);
                $accounting->approve($this->organization, $this->refresh($entry), $approver);
                $accounting->post((int) $company->id, $this->refresh($entry), $poster);
            }
        }
        if (($date = $this->day($month, 5)) !== null) {
            $entry = $this->manualEntry($company, $preparer, 'flow:reclassification', $date, 'reclassification', 'Reclasificación de papelería registrada como servicios básicos', 'RCL-'.$tag,
                [$line('5201', '180.00', '0'), $line('5204', '0', '180.00')], false);
            if ($entry !== null && $entry->status === 'draft') {
                $accounting->submitForReview($this->organization, $entry, $preparer);
                $accounting->review($this->organization, $this->refresh($entry), $accountant);
            }
        }
        if (($date = $this->day($month, 6)) !== null) {
            $entry = $this->manualEntry($company, $preparer, 'flow:approved', $date, 'standard', 'Registro de gastos de representación del mes', 'REP-'.$tag,
                [$line('5201', '640.00', '0'), $line('1101', '0', '640.00')]);
            if ($entry !== null && $entry->status === 'draft') {
                $accounting->submitForReview($this->organization, $entry, $preparer);
                $accounting->review($this->organization, $this->refresh($entry), $accountant);
                $accounting->approve($this->organization, $this->refresh($entry), $approver);
            }
        }
        if (($date = $this->day($month, 7)) !== null) {
            $entry = $this->manualEntry($company, $preparer, 'flow:rejected', $date, 'standard', 'Pago de telefonía del mes', 'TEL-'.$tag,
                [$line('5204', '448.00', '0'), $line('1102', '0', '448.00')], false);
            if ($entry !== null && $entry->status === 'draft') {
                $accounting->submitForReview($this->organization, $entry, $preparer);
                $accounting->reject($this->organization, $this->refresh($entry), $this->supervisorOf($company), 'Registro duplicado: el pago ya está contabilizado desde la compra al contado del proveedor.');
            }
        }
        if (($date = $this->day($month, 8)) !== null) {
            $this->manualEntry($company, $preparer, 'flow:draft', $date, 'standard', 'Estimación de intereses y cargos bancarios del mes', 'INT-'.$tag,
                [$line('5209', '95.00', '0'), $line('1102', '0', '95.00')], false);
        }
        if ($position === 1 && ($date = $this->day($month, 3)) !== null) {
            $wrong = $this->manualEntry($company, $accountant, 'flow:wrong-account', $date, 'standard', 'Pago de servicio de internet del mes', 'NET-'.$tag,
                [$line('5208', '672.00', '0'), $line('1102', '0', '672.00')]);
            if ($wrong !== null && $wrong->status === 'draft') {
                $accounting->post((int) $company->id, $wrong, $poster);
                $reversalOn = $this->paidOn($date, 1);
                $accounting->reverse($this->organization, $poster, (int) $company->id, $this->refresh($wrong), (int) $wrong->accounting_period_id, $reversalOn,
                    'El servicio de internet se registró en honorarios profesionales; corresponde a servicios básicos.');
                $right = $this->manualEntry($company, $accountant, 'flow:right-account', $reversalOn, 'standard', 'Pago de servicio de internet del mes (corrección)', 'NET-'.$tag,
                    [$line('5204', '672.00', '0'), $line('1102', '0', '672.00')]);
                if ($right !== null && $right->status === 'draft') {
                    $accounting->post((int) $company->id, $right, $poster);
                }
            }
        }
    }

    private function refresh(stdClass $entry): stdClass
    {
        return DB::table('journal_entries')->where('id', $entry->id)->firstOrFail();
    }

    /**
     * Calendario fiscal de cada empresa: lo de meses cerrados lo prepara la Encargada Fiscal y lo aprueba su revisor
     * (nunca quien lo preparó), salvo algunas que quedan en revisión, con cambios solicitados, en proceso o vencidas.
     *
     * @param  list<array{0: stdClass, 1: Profile}>  $companies
     * @param  list<CarbonImmutable>  $months
     */
    private function obligations(array $companies, array $months): void
    {
        $ids = array_map(fn (array $pair): int => (int) $pair[0]->id, $companies);
        app(FiscalObligationGenerationService::class)->generate($this->member, $this->owner, $ids);
        $holds = [];
        foreach ($companies as $index => [$company]) {
            $hold = match ($index) {
                0, 3 => [$months[1], 'review'],
                1 => [$months[1], 'changes'],
                2 => [$months[1], 'progress'],
                count($companies) - 1 => [$months[0], 'overdue'],
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
        $obligations = DB::table('fiscal_obligations')->whereIn('company_id', $ids)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable'])->orderBy('due_on')->orderBy('id')->get();
        foreach ($obligations as $obligation) {
            if (CarbonImmutable::create((int) $obligation->reference_year, (int) $obligation->reference_month, 1)->greaterThanOrEqualTo($months[2])) {
                continue;
            }
            $id = (int) $obligation->id;
            $hold = $holds[$id] ?? null;
            if ($hold === 'overdue') {
                continue;
            }
            $company = DB::table('companies')->where('id', $obligation->company_id)->firstOrFail();
            $preparer = $obligation->assigned_to === null ? $this->staff($company, 'tax') : (User::query()->whereKey((int) $obligation->assigned_to)->first() ?? $this->staff($company, 'tax'));
            // Revisa quien la obligación tiene como revisor (el Contador Encargado); nunca quien la preparó.
            $reviewer = $obligation->reviewer_id === null ? null : User::query()->whereKey((int) $obligation->reviewer_id)->first();
            $approver = $reviewer !== null && $reviewer->id !== $preparer->id ? $reviewer : ($preparer->id === $this->owner->id ? $this->bookkeeperOf($company) : $this->owner);
            $filedOn = CarbonImmutable::parse((string) $obligation->due_on)->subDays(3)->min(CarbonImmutable::today());
            $this->attempt($company, 'la obligación '.$obligation->name.' · '.$obligation->reference_period, function () use ($workflow, $obligation, $preparer, $approver, $filedOn, $company, $id, $hold): void {
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
                $this->at($filedOn->subDays(2)->setTime(16, 40), function () use ($workflow, $preparer, $id, &$status): void {
                    if ($status === 'in_progress') {
                        $workflow->submit($this->organization, $preparer, $id);
                        $status = 'ready_for_review';
                    }
                });
                if ($hold === 'review') {
                    return;
                }
                if ($hold === 'changes') {
                    $this->at($filedOn->subDay()->setTime(10, 30), fn () => $workflow->requestChanges($this->organization, $approver, $id,
                        'El crédito fiscal no coincide con el libro de compras: falta incluir una factura de combustible. Corregir y volver a enviar.', null, null, null));

                    return;
                }
                $this->at($filedOn->subDay()->setTime(10, 30), function () use ($workflow, $approver, $id, &$status): void {
                    if ($status === 'ready_for_review') {
                        $workflow->approve($this->organization, $approver, $id, 'Revisada contra libros de IVA y conciliación bancaria.');
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
     * Tareas de cada empresa en todos los estados del flujo, asignadas a quien corresponde por su rol en la sede y revisadas
     * por otra persona: completada, en proceso, en revisión, con cambios solicitados, aprobada, pendiente y vencida.
     *
     * @param  list<array{0: stdClass, 1: Profile}>  $companies
     * @param  list<CarbonImmutable>  $months
     */
    private function tasks(array $companies, array $months): void
    {
        $service = app(WorkTaskService::class);
        $label = fn (CarbonImmutable $month): string => mb_strtolower(SpanishMonth::label($month));
        foreach ($companies as [$company]) {
            $t = [...$this->team, ...$this->staffOf($company)];
            $definitions = [
                ['bank', 'banking', 'bank_reconciliation', 'Conciliar el estado de cuenta bancario de '.$label($months[1]), $t['treasury'], $t['accountant'], 'completed', -3, 'high'],
                ['sat', 'tax', 'sat_cross_check', 'Registrar las compras faltantes del cruce con la SAT de '.$label($months[1]), $t['assistant'], $t['accountant'], 'in_progress', 4, 'high'],
                ['receivables', 'receivables', 'receivables_review', 'Revisar la antigüedad de saldos de clientes y gestionar cobros vencidos', $t['receivables'], $t['supervisor'], 'in_review', 2, 'normal'],
                ['payables', 'payables', 'payables_review', 'Programar pagos a proveedores de la semana', $t['payables'], $t['supervisor'], 'approved', 1, 'normal'],
                ['closing', 'closing', 'monthly_close', 'Preparar el paquete de cierre de '.$label($months[1]), $t['accountant'], $t['general'], 'changes_requested', 3, 'high'],
                ['fiscal', 'tax', 'fiscal_review', 'Revisión fiscal de retenciones de ISR de '.$label($months[1]), $t['tax'], $t['supervisor'], 'completed', -1, 'normal'],
                ['client', 'client', 'client_documents', 'Solicitar al cliente los estados de cuenta y facturas de '.$label($months[2]), $t['assistant'], $t['accountant'], 'pending', 6, 'normal'],
                ['archive', 'documents', 'archive_documents', 'Archivar las facturas físicas de '.$label($months[0]), $t['assistant'], $t['accountant'], 'pending', -4, 'low'],
                ['statements', 'accounting', 'statements_review', 'Revisar los estados financieros de '.$label($months[0]).' antes de publicarlos', $t['general'], $this->owner, 'pending', 5, 'normal'],
            ];
            foreach ($definitions as [$key, $module, $type, $title, $assignee, $reviewer, $target, $days, $priority]) {
                $reviewer = $reviewer->id === $assignee->id ? $this->owner : $reviewer;
                $this->attempt($company, 'la tarea '.$title, function () use ($service, $company, $assignee, $reviewer, $key, $module, $type, $title, $target, $days, $priority): void {
                    $id = $service->create($this->member, $this->owner, ['office_id' => (int) $company->office_id, 'company_id' => (int) $company->id, 'module' => $module, 'task_type' => $type,
                        'title' => $title, 'assigned_to' => $assignee->id, 'reviewer_id' => $reviewer->id, 'priority' => $priority, 'due_at' => now()->addDays($days)->setTime(17, 0),
                        'automation_key' => 'plan-corporativo-demo:'.$company->id.':'.$key]);
                    if (DB::table('work_tasks')->where('id', $id)->value('status') !== 'pending' || $target === 'pending') {
                        return;
                    }
                    $service->transition($this->member, $assignee, $id, 'in_progress', 'Iniciada: se reunió la documentación del cliente.');
                    if ($target === 'in_progress') {
                        return;
                    }
                    $service->transition($this->member, $assignee, $id, 'ready_for_review', 'Lista para revisión.');
                    $service->transition($this->member, $reviewer, $id, 'in_review');
                    if ($target === 'in_review') {
                        return;
                    }
                    if ($target === 'changes_requested') {
                        $service->transition($this->member, $reviewer, $id, 'changes_requested', 'Falta adjuntar la conciliación de cuentas por pagar y la integración de anticipos.');

                        return;
                    }
                    $service->transition($this->member, $reviewer, $id, 'approved', 'Revisada sin observaciones.');
                    if ($target === 'completed') {
                        $service->transition($this->member, $reviewer, $id, 'completed');
                    }
                });
            }
        }
    }

    /**
     * Asiento manual en borrador y, cuando se pide, su documento de soporte (los asientos manuales contabilizados sin
     * soporte bloquean el cierre del período).
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
            $this->act($this->assistantOf($company), AccountingDocumentController::class, 'store', [], $company, ['entry' => $requestId], ['document' => $file]);
        }

        return DB::table('journal_entries')->where('public_id', $requestId)->first();
    }

    /**
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

    /** @param Closure(): mixed $callback */
    private function attempt(?stdClass $company, string $step, Closure $callback): void
    {
        $who = $company === null ? PlanCorporativoDemoCatalog::firm()['name'] : (string) $company->name;
        try {
            DB::transaction($callback);
        } catch (ValidationException $exception) {
            $this->warn("{$who}: no se completó {$step}. ".collect($exception->errors())->flatten()->implode(' '));
        } catch (HttpExceptionInterface $exception) {
            $this->warn("{$who}: no se completó {$step}. ".($exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP '.$exception->getStatusCode().' en '.basename($exception->getFile()).':'.$exception->getLine()));
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
        $grouped = fn ($query, string $column): string => $query->groupBy($column)->orderBy($column)->selectRaw($column.' as label, COUNT(*) as total')->get()
            ->map(fn (stdClass $row): string => $row->label.': '.$row->total)->implode(', ');
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->whereIn('p.company_id', $ids);
        $unbalanced = DB::query()->fromSub(DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->whereIn('p.company_id', $ids)->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->select('e.id'), 'unbalanced')->count();
        $rows = [
            ['Organización', PlanCorporativoDemoCatalog::firm()['name'].' (NIT '.PlanCorporativoDemoCatalog::ORGANIZATION_TAX_ID.')'],
            ['Oficinas', (string) DB::table('offices')->where('organization_id', $this->organization->id)->whereNull('deleted_at')->count()],
            ['Usuarios internos / del cliente', $this->internalUsers().' / '.DB::table('client_company_access')->whereIn('company_id', $ids)->count()],
            ['Empresas cliente', (string) count($companyIds)],
            ['Asignaciones por rol', $grouped(DB::table('company_assignments')->whereIn('company_id', $ids)->where('status', 'active')->whereNull('deleted_at'), 'assignment_role')],
            ['Clientes / proveedores', DB::table('sales_customers')->whereIn('company_id', $ids)->count().' / '.DB::table('purchase_suppliers')->whereIn('company_id', $ids)->count()],
            ['Facturas de venta / compras', DB::table('sales_invoices')->whereIn('company_id', $ids)->count().' / '.DB::table('purchase_documents')->whereIn('company_id', $ids)->count()],
            ['Notas de crédito (ventas / compras)', DB::table('sales_credit_notes')->whereIn('company_id', $ids)->count().' / '.DB::table('purchase_credit_notes')->whereIn('company_id', $ids)->count()],
            ['Asientos por estado', $grouped(clone $entries, 'e.status')],
            ['Asientos descuadrados', (string) $unbalanced],
            ['Productos / movimientos de kardex', DB::table('inventory_items')->whereIn('company_id', $ids)->count().' / '.DB::table('inventory_movements')->whereIn('company_id', $ids)->count()],
            ['Órdenes de producción / tomas físicas', DB::table('production_orders')->whereIn('company_id', $ids)->count().' / '.DB::table('inventory_counts')->whereIn('company_id', $ids)->count()],
            ['Empleados / nóminas', DB::table('payroll_employees')->whereIn('company_id', $ids)->count().' / '.$grouped(DB::table('payroll_periods')->whereIn('company_id', $ids), 'status')],
            ['Activos fijos', (string) DB::table('fixed_assets')->whereIn('company_id', $ids)->count()],
            ['Movimientos bancarios / conciliaciones', DB::table('bank_transactions as t')->join('bank_accounts as a', 'a.id', '=', 't.bank_account_id')->whereIn('a.company_id', $ids)->count()
                .' / '.DB::table('account_reconciliations')->whereIn('company_id', $ids)->count()],
            ['Períodos (abiertos / cerrados)', DB::table('accounting_periods')->whereIn('company_id', $ids)->whereNull('closed_at')->count().' / '.DB::table('accounting_periods')->whereIn('company_id', $ids)->whereNotNull('closed_at')->count()],
            ['Obligaciones por estado', $grouped(DB::table('fiscal_obligations')->whereIn('company_id', $ids), 'status')],
            ['Tareas por estado', $grouped(DB::table('work_tasks')->whereIn('company_id', $ids)->whereNull('deleted_at'), 'status')],
            ['Incidencias', (string) DB::table('operational_incidents')->whereIn('company_id', $ids)->count()],
            ['Revisiones del período por estado', $grouped(DB::table('company_period_reviews')->whereIn('company_id', $ids)->whereNull('deleted_at'), 'status')],
            ['Portal: solicitudes / documentos / mensajes / autorizaciones / reportes', DB::table('client_requests')->whereIn('company_id', $ids)->count().' / '.DB::table('client_documents')->whereIn('company_id', $ids)->count()
                .' / '.DB::table('client_conversations')->whereIn('company_id', $ids)->count().' / '.DB::table('client_approval_requests')->whereIn('company_id', $ids)->count()
                .' / '.DB::table('published_reports')->whereIn('company_id', $ids)->count()],
            ['Grupos consolidados / operaciones entre empresas', DB::table('company_groups')->where('organization_id', $this->organization->id)->count().' / '.count(PlanCorporativoDemoCatalog::intercompany())],
            ['Roles personalizados', (string) DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('is_system', false)->whereNull('deleted_at')->count()],
            ['Llaves de API (activas / revocadas) / llamadas registradas', DB::table('api_tokens')->where('organization_id', $this->organization->id)->whereNull('revoked_at')->count()
                .' / '.DB::table('api_tokens')->where('organization_id', $this->organization->id)->whereNotNull('revoked_at')->count()
                .' / '.DB::table('api_request_logs')->where('organization_id', $this->organization->id)->count()],
            ['Webhooks: destinos / entregas por estado', DB::table('webhook_endpoints')->where('organization_id', $this->organization->id)->count().' / '
                .$grouped(DB::table('webhook_deliveries')->where('organization_id', $this->organization->id), 'status')],
            ['Capacitación: cursos / inscripciones / certificaciones', DB::table('training_courses')->where('organization_id', $this->organization->id)->count()
                .' / '.DB::table('training_enrollments')->where('organization_id', $this->organization->id)->count()
                .' / '.DB::table('training_certifications')->where('organization_id', $this->organization->id)->count()],
            ['Pasos no completados', (string) count($this->warnings)],
        ];
        $this->command->table(['Registro', 'Resultado'], $rows);
        foreach ($this->apiResults as $line) {
            $this->command->line('  API v1 · '.$line);
        }
        $this->command->info('Usuarios: <correo>@'.PlanCorporativoDemoCatalog::DOMAIN.' y contactos @'.PlanCorporativoDemoCatalog::CLIENT_DOMAIN.' (ver README_SEEDER_PLAN_CORPORATIVO.md) · Cada usuario tiene su propia contraseña aleatoria.');
        if ($this->credentials !== []) {
            $this->command->warn('Credenciales de los usuarios creados ahora. Anótalas: no se guardan en claro y no se vuelven a mostrar.');
            $this->command->table(['Correo', 'Nombre', 'Contraseña'], $this->credentials);
        } else {
            $this->command->info('No se crearon usuarios nuevos: los existentes conservan su contraseña.');
        }
    }

    /** @param list<string> $lines */
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

    private function cents(string $amount): int
    {
        return app(AccountingService::class)->cents($amount);
    }

    private function memo(string $type): string
    {
        return match ($type) {
            'opening' => 'Existencia inicial al comenzar la contabilidad en Atlantia',
            'purchase' => 'Entrada por compra a proveedor',
            'sale' => 'Salida por factura de venta',
            default => 'Movimiento de inventario',
        };
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function uuid(stdClass $company, string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.plan-corporativo-demo.'.$company->id.'.'.$key)->toString();
    }

    private function authorization(stdClass $company, string $key): string
    {
        return Str::upper(Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.plan-corporativo-demo.dte.'.$company->id.'.'.$key)->toString());
    }
}
