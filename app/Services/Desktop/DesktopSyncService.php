<?php

declare(strict_types=1);

namespace App\Services\Desktop;

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\FinancialStatementService;
use App\Services\AccountingService;
use App\Services\Payables\PayableService;
use App\Services\PurchaseDocumentDraftService;
use App\Services\Receivables\ReceivableService;
use App\Services\SalesInvoiceDraftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The desktop app's offline mode. While online it downloads a snapshot of the companies the person can reach (only the
 * modules each role can see); while the server is unreachable the person reads that copy and captures sales, purchases
 * and journal entries; back online, each capture becomes a draft through the same services as the web forms, so
 * nothing is posted without the usual review.
 */
class DesktopSyncService
{
    /** Days of history the snapshot carries for journal entries, sales and purchases. */
    public const HISTORY_DAYS = 90;

    /** Days the app may keep its copy without reaching the server before it locks it. */
    public const MAX_OFFLINE_DAYS = 15;

    public const KINDS = ['sale' => 'Venta', 'purchase' => 'Compra o gasto', 'journal' => 'Asiento contable'];

    private const ROW_LIMIT = 500;

    public function __construct(
        private AccountingService $accounting,
        private FinancialStatementService $statements,
        private ReceivableService $receivables,
        private PayableService $payables,
        private SalesInvoiceDraftService $sales,
        private PurchaseDocumentDraftService $purchases,
    ) {}

    /**
     * Registers the computer (or refreshes it). A revoked device stays revoked: the app must erase its copy.
     *
     * @param  array{device_uuid: string, name: string, platform: string, app_version?: string|null}  $data
     */
    public function register(Organization $organization, User $user, array $data): stdClass
    {
        $data['device_uuid'] = Str::lower($data['device_uuid']);
        $existing = $this->device($organization, $user, $data['device_uuid']);
        if ($existing === null) {
            DB::table('desktop_devices')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'device_uuid' => $data['device_uuid'], 'name' => mb_substr($data['name'], 0, 120),
                'platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'desktop.device_registered', 'users', $user->id, ['device' => $data['name'], 'platform' => $data['platform']]);
        } elseif ($existing->revoked_at === null) {
            DB::table('desktop_devices')->where('id', $existing->id)->update(['name' => mb_substr($data['name'], 0, 120), 'app_version' => $data['app_version'] ?? null, 'last_seen_at' => now(), 'updated_at' => now()]);
        }

        return $this->device($organization, $user, $data['device_uuid']) ?? throw new \RuntimeException('Dispositivo no registrado.');
    }

    /** The registered, not revoked device; a revoked one answers 410 so the app erases its copy. */
    public function activeDevice(Organization $organization, User $user, string $uuid): stdClass
    {
        $device = $this->device($organization, $user, $uuid);
        abort_if($device === null, 404, 'Este equipo no está registrado.');
        abort_if($device->revoked_at !== null, 410, 'Este equipo fue desconectado de Atlantia Finance. Su copia sin conexión se borrará.');

        return $device;
    }

    /**
     * The offline copy: every company the person can reach, with only the parts their role can see.
     *
     * @return array{generated_at: string, max_offline_days: int, user: array{id: int, name: string}, organization: array{id: int, name: string}, companies: list<array<string, mixed>>}
     */
    public function snapshot(Organization $organization, User $user, stdClass $device): array
    {
        $today = now()->toDateString();
        $since = now()->subDays(self::HISTORY_DAYS)->toDateString();
        $companies = [];
        foreach ($this->accounting->companies($organization, $user)->orderBy('companies.name')->get() as $company) {
            $id = (int) $company->id;
            $can = fn (string $permission): bool => $this->accounting->canModule($organization, $id, $user, $permission);
            $range = $this->statements->fiscalRange($id, (int) now()->year);
            $companies[] = [
                'public_id' => (string) $company->public_id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id, 'office' => (string) $company->office_name,
                'can' => ['sale' => $can('sales.manage'), 'purchase' => $can('purchases.manage'), 'journal' => $can('journal.create')],
                'accounts' => DB::table('ledger_accounts')->where('company_id', $id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type'])
                    ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'type' => (string) $row->type])->all(),
                'periods' => DB::table('accounting_periods')->where('company_id', $id)->whereNull('closed_at')->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'open'))
                    ->orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on'])->map(fn (stdClass $row): array => (array) $row)->all(),
                'balances' => $can('ledger.view') ? array_values(array_filter($this->statements->balances($id, $today, $range['from']), fn (array $row): bool => $row['cents'] !== 0)) : null,
                'balances_from' => $range['from'],
                'customers' => $can('sales.view') ? DB::table('sales_customers')->where('company_id', $id)->orderBy('name')->limit(self::ROW_LIMIT)
                    ->get(['id', 'name', 'tax_id', 'email', 'payment_terms_days'])->map(fn (stdClass $row): array => (array) $row)->all() : null,
                'suppliers' => $can('purchases.view') ? DB::table('purchase_suppliers')->where('company_id', $id)->orderBy('name')->limit(self::ROW_LIMIT)
                    ->get(['id', 'name', 'tax_id'])->map(fn (stdClass $row): array => (array) $row)->all() : null,
                'receivables' => $can('sales.view') ? array_map(fn (array $row): array => ['document' => $row['document'], 'customer' => $row['customer'], 'due_date' => $row['due_date'],
                    'outstanding_cents' => $row['outstanding_cents'], 'overdue' => $row['overdue']], array_slice(array_values(array_filter($this->receivables->documents([$id]), fn (array $row): bool => $row['outstanding_cents'] > 0)), 0, self::ROW_LIMIT)) : null,
                'payables' => $can('purchases.view') ? array_map(fn (array $row): array => ['document' => $row['document'], 'supplier' => $row['supplier'], 'due_date' => $row['due_date'],
                    'outstanding_cents' => $row['outstanding_cents']], array_slice(array_values(array_filter($this->payables->documents([$id]), fn (array $row): bool => $row['outstanding_cents'] > 0)), 0, self::ROW_LIMIT)) : null,
                'entries' => $can('journal.view') ? DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $id)
                    ->where('e.entry_date', '>=', $since)->orderByDesc('e.entry_date')->orderByDesc('e.id')->limit(self::ROW_LIMIT)
                    ->get(['e.entry_date', 'e.description', 'e.reference', 'e.status', 'e.kind'])->map(fn (stdClass $row): array => (array) $row)->all() : null,
                'sales' => $can('sales.view') ? DB::table('sales_invoices as i')->join('sales_customers as c', 'c.id', '=', 'i.sales_customer_id')->where('i.company_id', $id)
                    ->where('i.issued_on', '>=', $since)->orderByDesc('i.issued_on')->limit(self::ROW_LIMIT)
                    ->get(['i.number', 'i.issued_on', 'i.status', 'i.total_cents', 'c.name as customer'])->map(fn (stdClass $row): array => (array) $row)->all() : null,
                'purchases' => $can('purchases.view') ? DB::table('purchase_documents as d')->join('purchase_suppliers as s', 's.id', '=', 'd.purchase_supplier_id')->where('d.company_id', $id)
                    ->where('d.document_date', '>=', $since)->orderByDesc('d.document_date')->limit(self::ROW_LIMIT)
                    ->get(['d.external_reference', 'd.number', 'd.document_date', 'd.status', 'd.total_cents', 's.name as supplier'])->map(fn (stdClass $row): array => (array) $row)->all() : null,
                'obligations' => $can('fiscal.view') ? DB::table('fiscal_obligations')->where('company_id', $id)->whereNotIn('status', ['filed', 'paid', 'not_applicable', 'cancelled'])
                    ->where('due_on', '<=', now()->addDays(60)->toDateString())->orderBy('due_on')->limit(100)->get(['name', 'reference_period', 'due_on', 'status'])->map(fn (stdClass $row): array => (array) $row)->all() : null,
            ];
        }
        DB::table('desktop_devices')->where('id', $device->id)->update(['last_snapshot_at' => now(), 'last_seen_at' => now(), 'updated_at' => now()]);

        return ['generated_at' => now()->toIso8601String(), 'max_offline_days' => self::MAX_OFFLINE_DAYS, 'user' => ['id' => $user->id, 'name' => $user->name],
            'organization' => ['id' => $organization->id, 'name' => $organization->name], 'companies' => $companies];
    }

    /**
     * Turns uploaded captures into drafts. Each capture is handled on its own and only once (by its id), so a repeated
     * upload after a dropped connection never duplicates anything.
     *
     * @param  list<array{id: string, company: string, kind: string, captured_at: string, payload: array<string, mixed>}>  $captures
     * @return list<array{id: string, status: string, message: string|null, url: string|null}>
     */
    public function upload(Organization $organization, User $user, stdClass $device, array $captures): array
    {
        $results = [];
        foreach ($captures as $capture) {
            $known = DB::table('offline_captures')->where('client_uuid', $capture['id'])->first(['status', 'error', 'draft_url', 'user_id']);
            if ($known !== null) {
                $results[] = (int) $known->user_id === $user->id
                    ? ['id' => $capture['id'], 'status' => (string) $known->status, 'message' => $known->error, 'url' => $known->draft_url]
                    : ['id' => $capture['id'], 'status' => 'failed', 'message' => 'Captura no válida.', 'url' => null];

                continue;
            }
            $company = $this->accounting->companies($organization, $user)->where('companies.public_id', $capture['company'])->first();
            if ($company === null) {
                $results[] = ['id' => $capture['id'], 'status' => 'failed', 'message' => 'La empresa ya no está disponible para ti.', 'url' => null];

                continue;
            }
            [$status, $message, $url] = $this->toDraft($organization, $user, $company, $capture);
            DB::table('offline_captures')->insert(['organization_id' => $organization->id, 'company_id' => $company->id, 'user_id' => $user->id, 'desktop_device_id' => $device->id,
                'client_uuid' => $capture['id'], 'kind' => $capture['kind'], 'payload' => json_encode($capture['payload'], JSON_THROW_ON_ERROR), 'captured_at' => Carbon::parse($capture['captured_at']),
                'status' => $status, 'error' => $message === null ? null : mb_substr($message, 0, 500), 'draft_url' => $url, 'created_at' => now(), 'updated_at' => now()]);
            $results[] = ['id' => $capture['id'], 'status' => $status, 'message' => $message, 'url' => $url];
        }
        DB::table('desktop_devices')->where('id', $device->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        if ($results !== []) {
            $this->accounting->audit($organization, $user, 'desktop.captures_uploaded', 'users', $user->id, ['device' => $device->name,
                'drafts' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'draft_created')), 'failed' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'failed'))]);
        }

        return $results;
    }

    /**
     * @param  array{id: string, company: string, kind: string, captured_at: string, payload: array<string, mixed>}  $capture
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function toDraft(Organization $organization, User $user, stdClass $company, array $capture): array
    {
        $payload = $capture['payload'];
        $base = '/accounting/companies/'.$company->public_id;
        $note = 'Capturado sin conexión el '.Carbon::parse($capture['captured_at'])->format('d/m/Y H:i');
        try {
            DB::beginTransaction();
            $permission = ['sale' => 'sales.manage', 'purchase' => 'purchases.manage', 'journal' => 'journal.create'][$capture['kind']] ?? null;
            if ($permission === null || ! $this->accounting->canModule($organization, (int) $company->id, $user, $permission)) {
                throw ValidationException::withMessages(['capture' => 'No tienes permiso para registrar este tipo de movimiento en la empresa.']);
            }
            $url = match ($capture['kind']) {
                'sale' => $this->sale($organization, $user, $company, $capture['id'], $payload, $base, $note),
                'purchase' => $this->purchase($organization, $user, $company, $capture['id'], $payload, $base, $note),
                default => $this->journal($organization, $user, $company, $capture['id'], $payload, $base, $note),
            };
            DB::commit();

            return ['draft_created', null, $url];
        } catch (ValidationException $exception) {
            DB::rollBack();

            return ['failed', implode(' ', array_map(fn (array $messages): string => implode(' ', $messages), $exception->errors())), null];
        } catch (HttpExceptionInterface $exception) {
            DB::rollBack();

            return ['failed', $exception->getMessage() ?: 'No se pudo crear el borrador.', null];
        } catch (Throwable $exception) {
            DB::rollBack();
            report($exception);

            return ['failed', 'No se pudo crear el borrador. Revísalo en Atlantia Finance.', null];
        }
    }

    /** @param array<string, mixed> $payload */
    private function sale(Organization $organization, User $user, stdClass $company, string $uuid, array $payload, string $base, string $note): string
    {
        $this->sales->create($organization, $user, $company, ['request_id' => $uuid, 'customer_id' => (int) ($payload['customer_id'] ?? 0), 'issued_on' => (string) ($payload['date'] ?? ''),
            'due_on' => isset($payload['due_on']) ? (string) $payload['due_on'] : null, 'lines' => array_map(fn (array $line): array => ['description' => $line['description'], 'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'], 'tax_treatment' => $line['tax_treatment']], $this->documentLines($payload))],
            ['source' => 'desktop_offline', 'note' => $note]);

        return $base.'/sales/invoices/'.$uuid;
    }

    /** @param array<string, mixed> $payload */
    private function purchase(Organization $organization, User $user, stdClass $company, string $uuid, array $payload, string $base, string $note): string
    {
        $this->purchases->create($organization, $user, $company, ['request_id' => $uuid, 'supplier_id' => (int) ($payload['supplier_id'] ?? 0),
            'document_type' => ($payload['document_type'] ?? 'purchase') === 'expense' ? 'expense' : 'purchase', 'external_reference' => isset($payload['reference']) ? mb_substr((string) $payload['reference'], 0, 100) : null,
            'document_date' => (string) ($payload['date'] ?? ''), 'due_on' => isset($payload['due_on']) ? (string) $payload['due_on'] : null,
            'lines' => $this->documentLines($payload)], ['source' => 'desktop_offline', 'note' => $note]);

        return $base.'/purchases/documents/'.$uuid;
    }

    /** @param array<string, mixed> $payload */
    private function journal(Organization $organization, User $user, stdClass $company, string $uuid, array $payload, string $base, string $note): string
    {
        $date = (string) ($payload['date'] ?? '');
        $period = DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'open'))
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->value('id');
        if ($period === null) {
            throw ValidationException::withMessages(['date' => 'No hay un período contable abierto para el '.$date.'.']);
        }
        $lines = array_map(fn (array $line): array => ['account_id' => (int) ($line['account_id'] ?? 0), 'debit' => (string) ($line['debit'] ?? '0'), 'credit' => (string) ($line['credit'] ?? '0'),
            'description' => mb_substr((string) ($line['description'] ?? $payload['description'] ?? ''), 0, 250)], array_values((array) ($payload['lines'] ?? [])));
        $this->accounting->createDraft($organization, $user, $company, ['request_id' => $uuid, 'period_id' => (int) $period, 'entry_date' => $date,
            'description' => mb_substr((string) ($payload['description'] ?? 'Asiento capturado sin conexión'), 0, 250), 'reference' => mb_substr($note, 0, 60), 'kind' => 'standard',
            'posting_key' => 'desktop:'.$uuid], $this->accounting->lines((int) $company->id, $lines));

        return $base.'#libro-diario';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{description: string, quantity: int, unit_price: string, tax_treatment: string, account_id: int}>
     */
    private function documentLines(array $payload): array
    {
        $lines = [];
        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $treatment = $line['tax_treatment'] ?? null;
            $lines[] = ['description' => mb_substr((string) ($line['description'] ?? ''), 0, 250), 'quantity' => (int) ($line['quantity'] ?? 1), 'unit_price' => (string) ($line['unit_price'] ?? '0'),
                'tax_treatment' => in_array($treatment, ['goods', 'services', 'exempt'], true) ? $treatment : 'services', 'account_id' => (int) ($line['account_id'] ?? 0)];
        }

        return $lines;
    }

    /**
     * The computers with the app: the person's own, or every one of the firm for its owner.
     *
     * @return list<array{id: int, name: string, platform: string, app_version: string|null, user: string, last_seen_at: string|null, last_snapshot_at: string|null, revoked_at: string|null, mine: bool}>
     */
    public function devices(Organization $organization, User $user, bool $wholeFirm): array
    {
        return array_values(DB::table('desktop_devices')->join('users', 'users.id', '=', 'desktop_devices.user_id')->where('desktop_devices.organization_id', $organization->id)
            ->when(! $wholeFirm, fn ($query) => $query->where('desktop_devices.user_id', $user->id))->orderByRaw('desktop_devices.revoked_at IS NOT NULL')->orderByDesc('desktop_devices.last_seen_at')
            ->get(['desktop_devices.*', 'users.name as user_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'platform' => (string) $row->platform, 'app_version' => $row->app_version,
                'user' => (string) $row->user_name, 'last_seen_at' => $row->last_seen_at, 'last_snapshot_at' => $row->last_snapshot_at, 'revoked_at' => $row->revoked_at,
                'mine' => (int) $row->user_id === $user->id])->all());
    }

    /** Disconnects a computer: the next time the app reaches the server it erases its offline copy. */
    public function revoke(Organization $organization, User $user, int $deviceId, bool $wholeFirm): void
    {
        $device = DB::table('desktop_devices')->where('desktop_devices.organization_id', $organization->id)->where('id', $deviceId)
            ->when(! $wholeFirm, fn ($query) => $query->where('user_id', $user->id))->first();
        abort_if($device === null, 404);
        DB::table('desktop_devices')->where('id', $deviceId)->update(['revoked_at' => now(), 'revoked_by' => $user->id, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'desktop.device_revoked', 'users', (int) $device->user_id, ['device' => $device->name]);
    }

    /**
     * Captures uploaded from the desktop app, in the companies the person can reach.
     *
     * @return list<array{id: int, company: string, kind: string, kind_label: string, status: string, error: string|null, draft_url: string|null, captured_at: string, uploaded_at: string, user: string, summary: string}>
     */
    public function captures(Organization $organization, User $user): array
    {
        $companyIds = $this->accounting->companies($organization, $user)->pluck('companies.id')->all();

        return array_values(DB::table('offline_captures')->join('companies', 'companies.id', '=', 'offline_captures.company_id')->join('users', 'users.id', '=', 'offline_captures.user_id')
            ->whereIn('offline_captures.company_id', $companyIds === [] ? [-1] : $companyIds)->whereNull('offline_captures.resolved_at')
            ->orderByRaw("offline_captures.status = 'failed' DESC")->orderByDesc('offline_captures.created_at')->limit(200)
            ->get(['offline_captures.*', 'companies.name as company_name', 'users.name as user_name'])
            ->map(function (stdClass $row): array {
                $payload = (array) json_decode((string) $row->payload, true);

                return ['id' => (int) $row->id, 'company' => (string) $row->company_name, 'kind' => (string) $row->kind, 'kind_label' => self::KINDS[$row->kind] ?? (string) $row->kind,
                    'status' => (string) $row->status, 'error' => $row->error, 'draft_url' => $row->draft_url, 'captured_at' => (string) $row->captured_at, 'uploaded_at' => (string) $row->created_at,
                    'user' => (string) $row->user_name, 'summary' => trim(($payload['description'] ?? '').' '.($payload['date'] ?? ''))];
            })->all());
    }

    /** The accountant dismisses a capture: once its draft was reviewed, or when a failed one will be entered by hand. */
    public function dismiss(Organization $organization, User $user, int $captureId): void
    {
        $companyIds = $this->accounting->companies($organization, $user)->pluck('companies.id')->all();
        $updated = DB::table('offline_captures')->where('id', $captureId)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by' => $user->id, 'updated_at' => now()]);
        abort_if($updated === 0, 404);
    }

    private function device(Organization $organization, User $user, string $uuid): ?stdClass
    {
        return DB::table('desktop_devices')->where('desktop_devices.organization_id', $organization->id)->where('user_id', $user->id)->where('device_uuid', Str::lower($uuid))->first();
    }
}
