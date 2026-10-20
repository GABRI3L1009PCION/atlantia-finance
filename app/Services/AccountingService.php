<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\CostCenterService;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class AccountingService
{
    /** Journal kinds that are adjustments: whoever creates one cannot approve it. */
    public const ADJUSTMENT_KINDS = ['adjustment', 'reclassification', 'provision', 'closing'];

    private const STRICT_READ_ONLY_ROLE_CODES = ['auditor', 'read_only'];

    /** Roles that oversee every company of their office instead of being assigned company by company. Read-only access is granted per company. */
    public const OFFICE_WIDE_ROLE_CODES = ['general_accountant', 'accounting_manager'];

    private const JOURNAL_PERMISSIONS = [
        'view' => 'journal.view',
        'create' => 'journal.create',
        'attach_support' => 'journal.attach_support',
        'submit' => 'journal.submit',
        'review' => 'journal.review',
        'approve' => 'journal.approve',
        'post' => 'journal.post',
        'reject' => 'journal.reject',
        'reverse' => 'journal.reverse',
        'void' => 'journal.void',
        'import' => 'journal.import',
    ];

    private const EDITOR_FALLBACK_PERMISSIONS = [
        'sales.manage', 'sales.export',
        'purchases.manage', 'purchases.export',
        'banks.manage', 'banks.export',
        'fel.manage', 'fel.certify', 'fel.cancel', 'fel.accounting',
        'journal.create', 'journal.attach_support', 'journal.submit',
        'documents.manage',
        'operations.manage',
    ];

    private const DEFAULT_CHART = [
        ['code' => '1101', 'name' => 'Caja', 'type' => 'asset'],
        ['code' => '1102', 'name' => 'Bancos', 'type' => 'asset'],
        ['code' => '1103', 'name' => 'Cuentas por cobrar', 'type' => 'asset'],
        ['code' => '1201', 'name' => 'Inventarios', 'type' => 'asset'],
        ['code' => '1301', 'name' => 'IVA crédito fiscal', 'type' => 'asset'],
        ['code' => '1401', 'name' => 'Propiedad, planta y equipo', 'type' => 'asset'],
        ['code' => '1402', 'name' => 'Depreciación acumulada', 'type' => 'asset'],
        ['code' => '1403', 'name' => 'Activos intangibles', 'type' => 'asset'],
        ['code' => '1404', 'name' => 'Amortización acumulada', 'type' => 'asset'],
        ['code' => '2101', 'name' => 'Proveedores', 'type' => 'liability'],
        ['code' => '2102', 'name' => 'Cuentas por pagar', 'type' => 'liability'],
        ['code' => '2201', 'name' => 'IVA débito fiscal', 'type' => 'liability'],
        ['code' => '2301', 'name' => 'Préstamos por pagar', 'type' => 'liability'],
        ['code' => '3101', 'name' => 'Capital aportado', 'type' => 'equity'],
        ['code' => '3201', 'name' => 'Resultados acumulados', 'type' => 'equity'],
        ['code' => '4101', 'name' => 'Ingresos por servicios', 'type' => 'income'],
        ['code' => '4201', 'name' => 'Ventas', 'type' => 'income'],
        ['code' => '5101', 'name' => 'Costo de ventas', 'type' => 'expense'],
        ['code' => '5201', 'name' => 'Gastos administrativos', 'type' => 'expense'],
        ['code' => '5202', 'name' => 'Sueldos y salarios', 'type' => 'expense'],
        ['code' => '5203', 'name' => 'Alquileres', 'type' => 'expense'],
        ['code' => '5204', 'name' => 'Servicios básicos', 'type' => 'expense'],
        ['code' => '5205', 'name' => 'Gastos bancarios', 'type' => 'expense'],
        ['code' => '5206', 'name' => 'Gasto por depreciación', 'type' => 'expense'],
        ['code' => '5207', 'name' => 'Gasto por amortización', 'type' => 'expense'],
        ['code' => '5208', 'name' => 'Honorarios profesionales', 'type' => 'expense'],
        ['code' => '5209', 'name' => 'Intereses y gastos financieros', 'type' => 'expense'],
    ];

    /** Install an idempotent starter chart without overwriting custom accounts. */
    public function installDefaultChart(int $companyId): int
    {
        $now = now();
        $rows = array_map(fn (array $account): array => [
            ...$account,
            'company_id' => $companyId,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULT_CHART);

        return DB::table('ledger_accounts')->insertOrIgnore($rows);
    }

    /** Scope all reads to an active organization, office and explicit assignment. */
    public function companies(Organization $organization, User $user): Builder
    {
        $query = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organization->id)->where('offices.status', 'active')
            ->whereNull('offices.deleted_at')->select('companies.*', 'offices.name as office_name');
        if ($organization->pivot->role !== 'owner') {
            // Most roles reach only the companies they are assigned to. The general accountant, the accounting manager and the
            // read-only role oversee a whole office, so their role in an office opens every company of that office.
            $query->where(fn (Builder $access) => $access
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('company_user')->whereColumn('company_id', 'companies.id')->where('user_id', $user->id))
                ->orWhereExists(fn (Builder $q) => $q->selectRaw('1')->from('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
                    ->where('ura.organization_id', $organization->id)->where('ura.user_id', $user->id)->where('ura.status', 'active')->whereNull('ura.deleted_at')
                    ->where('role.status', 'active')->whereIn('role.code', self::OFFICE_WIDE_ROLE_CODES)
                    ->where(fn (Builder $scope) => $scope->whereNull('ura.office_id')->orWhereColumn('ura.office_id', 'companies.office_id'))))
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('office_user')->whereColumn('office_user.office_id', 'companies.office_id')->where('office_user.user_id', $user->id)->where('office_user.status', 'active'));
        }

        return $query;
    }

    public function company(Organization $organization, User $user, string $uuid, bool $write = false): stdClass
    {
        $company = $this->companies($organization, $user)->where('companies.public_id', $uuid)->firstOrFail();
        if ($write && $organization->pivot->role !== 'owner') {
            abort_unless($this->canWriteAny($organization, (int) $company->id, $user), 403);
        }

        return $company;
    }

    public function companyPermission(Organization $organization, int $companyId, User $user): string
    {
        if ($organization->pivot->role === 'owner') {
            return 'owner';
        }

        return (string) (DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->value('permission') ?? 'none');
    }

    public function canJournal(Organization $organization, int $companyId, User $user, string $ability): bool
    {
        $permission = self::JOURNAL_PERMISSIONS[$ability] ?? null;

        return $permission !== null && $this->canModule($organization, $companyId, $user, $permission);
    }

    public function assertModule(Organization $organization, int $companyId, User $user, string $permission): void
    {
        abort_unless($this->canModule($organization, $companyId, $user, $permission), 403);
    }

    public function canModule(Organization $organization, int $companyId, User $user, string $permission): bool
    {
        if ($this->organizationRole($organization, $user) === 'owner') {
            return true;
        }

        if (! $this->hasCompanyAccess($organization, $companyId, $user)) {
            return false;
        }

        $isRead = $this->isReadPermission($permission);
        if (! $isRead && $this->hasStrictReadOnlyRole($organization, $companyId, $user)) {
            return false;
        }

        if ($this->hasWorkspacePermission($organization, $companyId, $user, $permission)) {
            return true;
        }

        $legacy = $this->companyPermission($organization, $companyId, $user);
        if ($isRead) {
            return in_array($legacy, ['viewer', 'editor'], true);
        }

        return $legacy === 'editor' && in_array($permission, self::EDITOR_FALLBACK_PERMISSIONS, true);
    }

    private function canWriteAny(Organization $organization, int $companyId, User $user): bool
    {
        if ($this->organizationRole($organization, $user) === 'owner') {
            return true;
        }

        if ($this->hasStrictReadOnlyRole($organization, $companyId, $user)) {
            return false;
        }

        return $this->companyPermission($organization, $companyId, $user) === 'editor'
            || $this->hasAnyWorkspaceWritePermission($organization, $companyId, $user);
    }

    private function isReadPermission(string $permission): bool
    {
        return Str::endsWith($permission, '.view') || Str::endsWith($permission, '.export');
    }

    private function hasCompanyAccess(Organization $organization, int $companyId, User $user): bool
    {
        return $this->companies($organization, $user)->where('companies.id', $companyId)->exists();
    }

    private function hasStrictReadOnlyRole(Organization $organization, int $companyId, User $user): bool
    {
        $hasReadOnlyWorkspaceRole = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $user->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->whereIn('role.code', self::STRICT_READ_ONLY_ROLE_CODES)
            ->exists();

        return $hasReadOnlyWorkspaceRole
            || DB::table('company_assignments')
                ->where('company_assignments.organization_id', $organization->id)
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->where('assignment_role', 'auditor')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->exists();
    }

    private function hasWorkspacePermission(Organization $organization, int $companyId, User $user, string $permission): bool
    {
        return $this->workspacePermissionQuery($organization, $companyId, $user)
            ->where('permission.code', $permission)
            ->exists();
    }

    private function hasAnyWorkspaceWritePermission(Organization $organization, int $companyId, User $user): bool
    {
        return $this->workspacePermissionQuery($organization, $companyId, $user)
            ->where('permission.code', 'not like', '%.view')
            ->where('permission.code', 'not like', '%.export')
            ->whereNotIn('permission.code', ['client.portal.view'])
            ->exists();
    }

    private function workspacePermissionQuery(Organization $organization, int $companyId, User $user): Builder
    {
        $officeId = DB::table('companies')->where('id', $companyId)->value('office_id');

        return DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->join('workspace_role_permissions as rp', 'rp.workspace_role_id', '=', 'role.id')
            ->join('workspace_permissions as permission', 'permission.id', '=', 'rp.workspace_permission_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $user->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where(fn (Builder $query) => $query->whereNull('ura.office_id')->orWhere('ura.office_id', $officeId));
    }

    private function organizationRole(Organization $organization, User $user): string
    {
        if ($organization->relationLoaded('pivot') && $organization->pivot->role && (int) $organization->pivot->user_id === $user->id) {
            return (string) $organization->pivot->role;
        }

        return (string) (DB::table('organization_user')
            ->where('organization_user.organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role') ?? 'member');
    }

    /** Lock company first for all writes: posting, period closure and numbering cannot race. */
    public function lock(int $companyId): void
    {
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();
    }

    public function openPeriod(int $companyId, int $periodId, string $date): stdClass
    {
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->first();
        if (! $period || $period->closed_at || in_array($period->status ?? 'open', ['closed', 'reopen_requested', 'reopen_approved'], true) || $date < $period->starts_on || $date > $period->ends_on) {
            throw ValidationException::withMessages(['accounting' => 'Selecciona un período abierto que incluya la fecha del asiento.']);
        }

        return $period;
    }

    /** Exact decimal conversion; floats are never used for journal arithmetic. */
    public function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $amount)) {
            throw ValidationException::withMessages(['accounting' => 'Usa importes positivos con hasta 10 enteros y 2 decimales.']);
        }
        $parts = explode('.', $amount);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    /**
     * Validate double entry and reject accounts from any other company.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{ledger_account_id: int, position: int, description: ?string, third_party_type: ?string, third_party_id: ?int, cost_center: ?string, project: ?string, debit_cents: int, credit_cents: int}>
     */
    public function lines(int $companyId, array $lines): array
    {
        $normalized = [];
        $debit = $credit = 0;
        $accounts = DB::table('ledger_accounts')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('accepts_movements', true)
            ->where('is_group', false)
            ->pluck('id')
            ->all();
        foreach ($lines as $index => $line) {
            $accountId = $line['account_id'] ?? null;
            if (! is_scalar($accountId) || ! is_scalar($line['debit'] ?? null) || ! is_scalar($line['credit'] ?? null)) {
                throw ValidationException::withMessages(['accounting' => 'Usa un importe numérico en cada línea.']);
            }
            $d = $this->cents((string) $line['debit']);
            $c = $this->cents((string) $line['credit']);
            if (! in_array((int) $accountId, $accounts) || ($d > 0) === ($c > 0)) {
                throw ValidationException::withMessages(['accounting' => 'Cada línea necesita una cuenta activa de esta empresa que acepte movimientos y solo debe o solo haber mayor a cero.']);
            }
            $normalized[] = [
                'ledger_account_id' => (int) $accountId,
                'position' => $index + 1,
                'description' => filled($line['description'] ?? null) ? mb_substr((string) $line['description'], 0, 255) : null,
                'third_party_type' => filled($line['third_party_type'] ?? null) ? mb_substr((string) $line['third_party_type'], 0, 60) : null,
                'third_party_id' => is_numeric($line['third_party_id'] ?? null) ? (int) $line['third_party_id'] : null,
                'cost_center' => filled($line['cost_center'] ?? null) ? mb_substr((string) $line['cost_center'], 0, 120) : null,
                'project' => filled($line['project'] ?? null) ? mb_substr((string) $line['project'], 0, 120) : null,
                'debit_cents' => $d,
                'credit_cents' => $c,
            ];
            $debit += $d;
            $credit += $c;
        }
        if (count($normalized) < 2 || $debit === 0 || $debit !== $credit) {
            throw ValidationException::withMessages(['accounting' => 'El asiento necesita al menos dos líneas y el debe debe ser igual al haber.']);
        }
        app(CostCenterService::class)->validateLines($companyId, $normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(Organization $organization, User $user, stdClass $company, array $data, array $lines): int
    {
        if (DB::table('journal_entries')->where('public_id', $data['request_id'])->exists()) {
            throw ValidationException::withMessages(['accounting' => 'Esta solicitud ya fue registrada. Consulta el diario antes de volver a enviarla.']);
        }

        if (filled($data['posting_key'] ?? null)) {
            $existing = DB::table('journal_entries')->where('posting_key', $data['posting_key'])->first();
            if ($existing) {
                throw ValidationException::withMessages(['accounting' => 'Esta operación ya posee el asiento DI-'.str_pad((string) ($existing->number ?? $existing->id), 6, '0', STR_PAD_LEFT).'.']);
            }
        }

        $period = $this->openPeriod($company->id, (int) $data['period_id'], $data['entry_date']);
        if ($data['kind'] === 'opening' && $data['entry_date'] !== $period->starts_on) {
            throw ValidationException::withMessages(['accounting' => 'Los saldos iniciales deben registrarse al inicio del período.']);
        }

        $id = DB::table('journal_entries')->insertGetId([
            'public_id' => $data['request_id'],
            'accounting_period_id' => $period->id,
            'entry_date' => $data['entry_date'],
            'description' => $data['description'],
            'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
            'kind' => $data['kind'],
            'status' => 'draft',
            'source_type' => filled($data['source_type'] ?? null) ? $data['source_type'] : null,
            'source_id' => is_numeric($data['source_id'] ?? null) ? (int) $data['source_id'] : null,
            'accounting_event' => filled($data['accounting_event'] ?? null) ? $data['accounting_event'] : null,
            'posting_key' => filled($data['posting_key'] ?? null) ? $data['posting_key'] : null,
            'created_by' => $user->id,
            'metadata' => json_encode(['origin' => filled($data['source_type'] ?? null) ? 'automatic_or_origin' : 'manual'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert(array_map(fn ($line) => [...$line, 'journal_entry_id' => $id], $lines));
        if (filled($data['source_type'] ?? null) && is_numeric($data['source_id'] ?? null)) {
            DB::table('accounting_source_entries')->insertOrIgnore([
                'company_id' => $company->id,
                'source_type' => $data['source_type'],
                'source_id' => (int) $data['source_id'],
                'event' => 'origin',
                'journal_entry_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->audit($organization, $user, 'journal_entry.created', 'journal_entries', $id, [
            'source_type' => $data['source_type'] ?? null,
            'accounting_event' => $data['accounting_event'] ?? null,
        ]);

        return $id;
    }

    public function submitForReview(Organization $organization, stdClass $entry, User $user): void
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['accounting' => 'Solo los borradores pueden enviarse a revisión.']);
        }
        DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'pending_review', 'updated_at' => now()]);
        $this->audit($organization, $user, 'journal_entry.review_requested', 'journal_entries', $entry->id);
    }

    public function review(Organization $organization, stdClass $entry, User $user): void
    {
        if (! in_array($entry->status, ['draft', 'pending_review'], true)) {
            throw ValidationException::withMessages(['accounting' => 'Este asiento no está disponible para revisión.']);
        }
        DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        $this->audit($organization, $user, 'journal_entry.reviewed', 'journal_entries', $entry->id);
    }

    public function approve(Organization $organization, stdClass $entry, User $user): void
    {
        if (! in_array($entry->status, ['draft', 'pending_review', 'reviewed'], true)) {
            throw ValidationException::withMessages(['accounting' => 'Este asiento no está disponible para aprobación.']);
        }
        if (in_array($entry->kind ?? 'standard', self::ADJUSTMENT_KINDS, true) && (int) $entry->created_by === $user->id) {
            throw ValidationException::withMessages(['accounting' => 'Un ajuste debe aprobarlo una persona distinta a quien lo creó.']);
        }
        DB::table('journal_entries')->where('id', $entry->id)->update([
            'status' => 'approved',
            'reviewed_by' => $entry->reviewed_by ?? $user->id,
            'reviewed_at' => $entry->reviewed_at ?? now(),
            'approved_by' => $user->id,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($organization, $user, 'journal_entry.approved', 'journal_entries', $entry->id);
    }

    public function post(int $companyId, stdClass $entry, User $user): void
    {
        if (! in_array($entry->status, ['draft', 'reviewed', 'approved'], true)) {
            throw ValidationException::withMessages(['accounting' => 'Este asiento ya fue procesado.']);
        }
        $period = $this->openPeriod($companyId, $entry->accounting_period_id, $entry->entry_date);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $accountIds = DB::table('ledger_accounts')->where('company_id', $companyId)->where('is_active', true)->where('accepts_movements', true)->where('is_group', false)->pluck('id');
        if ($lines->count() < 2 || $lines->sum('debit_cents') <= 0 || $lines->sum('debit_cents') !== $lines->sum('credit_cents') || $lines->contains(fn ($l) => ! $accountIds->contains($l->ledger_account_id) || $l->debit_cents < 0 || $l->credit_cents < 0 || ($l->debit_cents > 0) === ($l->credit_cents > 0))) {
            throw ValidationException::withMessages(['accounting' => 'El asiento no supera la validación de integridad.']);
        }
        $number = (int) DB::table('journal_entries')->where('accounting_period_id', $period->id)->max('number') + 1;
        DB::table('journal_entries')->where('id', $entry->id)->update([
            'number' => $number,
            'status' => 'posted',
            'approved_by' => $entry->approved_by ?? $user->id,
            'approved_at' => $entry->approved_at ?? now(),
            'posted_by' => $user->id,
            'posted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function reject(Organization $organization, stdClass $entry, User $user, string $reason): void
    {
        if (! in_array($entry->status, ['draft', 'pending_review', 'reviewed', 'approved'], true)) {
            throw ValidationException::withMessages(['accounting' => 'Este asiento ya no puede rechazarse.']);
        }
        DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'rejected', 'rejected_by' => $user->id, 'rejected_at' => now(), 'rejection_reason' => $reason, 'updated_at' => now()]);
        $this->audit($organization, $user, 'journal_entry.rejected', 'journal_entries', $entry->id, ['reason' => $reason]);
    }

    public function reverse(Organization $organization, User $user, int $companyId, stdClass $entry, int $periodId, string $date, string $reason): int
    {
        if ($entry->status !== 'posted' || $entry->reversal_of || DB::table('journal_entries')->where('reversal_of', $entry->id)->exists()) {
            throw ValidationException::withMessages(['accounting' => 'Este asiento no admite otro reverso.']);
        }
        $period = $this->openPeriod($companyId, $periodId, $date);
        if ($date < $entry->entry_date) {
            throw ValidationException::withMessages(['accounting' => 'El reverso no puede ser anterior al asiento original.']);
        }

        $newId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'accounting_period_id' => $period->id,
            'entry_date' => $date,
            'description' => 'Reverso: '.$reason,
            'reference' => $entry->reference,
            'kind' => 'reversal',
            'status' => 'draft',
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'accounting_event' => $entry->accounting_event ? $entry->accounting_event.'.reversed' : 'journal_entry.reversed',
            'created_by' => $user->id,
            'reversal_of' => $entry->id,
            'metadata' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert($this->reversalLines($entry->id, $newId));
        $this->post($companyId, $this->row(DB::table('journal_entries')->where('id', $newId)), $user);
        DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'reversed', 'updated_at' => now()]);
        $this->audit($organization, $user, 'journal_entry.reversed', 'journal_entries', $entry->id, ['reason' => $reason, 'reversal_entry_id' => $newId]);
        $this->audit($organization, $user, 'journal_entry.reversal_created', 'journal_entries', $newId, ['reason' => $reason, 'reversal_of' => $entry->id]);

        return $newId;
    }

    /** @return list<array<string, mixed>> */
    private function reversalLines(int $entryId, int $newEntryId): array
    {
        return array_values(DB::table('journal_lines')->where('journal_entry_id', $entryId)->orderBy('position')->get()
            ->map(fn ($line): array => [
                'journal_entry_id' => $newEntryId,
                'ledger_account_id' => $line->ledger_account_id,
                'position' => $line->position,
                'description' => $line->description,
                'third_party_type' => $line->third_party_type,
                'third_party_id' => $line->third_party_id,
                'cost_center' => $line->cost_center,
                'project' => $line->project,
                'debit_cents' => $line->credit_cents,
                'credit_cents' => $line->debit_cents,
            ])->all());
    }

    private function row(Builder $query): stdClass
    {
        return $query->firstOrFail();
    }

    /** @param array<string, mixed> $details */
    public function audit(Organization $organization, User $user, string $action, string $table, int $id, array $details = [], ?int $officeId = null): void
    {
        DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'office_id' => $officeId, 'user_id' => $user->id, 'action' => $action, 'auditable_type' => $table, 'auditable_id' => $id, 'new_values' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'created_at' => now()]);
        // Audited actions are the source of the organization's outgoing webhooks.
        app(WebhookDispatcher::class)->fromAudit((int) $organization->id, $action, $table, $id);
    }
}
