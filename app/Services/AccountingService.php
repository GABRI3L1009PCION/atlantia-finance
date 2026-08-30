<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class AccountingService
{
    private const DEFAULT_CHART = [
        ['code' => '1101', 'name' => 'Caja', 'type' => 'asset'],
        ['code' => '1102', 'name' => 'Bancos', 'type' => 'asset'],
        ['code' => '1103', 'name' => 'Cuentas por cobrar', 'type' => 'asset'],
        ['code' => '1201', 'name' => 'Inventarios', 'type' => 'asset'],
        ['code' => '1301', 'name' => 'IVA crédito fiscal', 'type' => 'asset'],
        ['code' => '1401', 'name' => 'Propiedad, planta y equipo', 'type' => 'asset'],
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
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('company_user')->whereColumn('company_id', 'companies.id')->where('user_id', $user->id))
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('office_user')->whereColumn('office_user.office_id', 'companies.office_id')->where('office_user.user_id', $user->id)->where('office_user.status', 'active'));
        }

        return $query;
    }

    public function company(Organization $organization, User $user, string $uuid, bool $write = false): stdClass
    {
        $company = $this->companies($organization, $user)->where('companies.public_id', $uuid)->firstOrFail();
        if ($write && $organization->pivot->role !== 'owner') {
            abort_unless(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->where('permission', 'editor')->exists(), 403);
        }

        return $company;
    }

    /** Lock company first for all writes: posting, period closure and numbering cannot race. */
    public function lock(int $companyId): void
    {
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();
    }

    public function openPeriod(int $companyId, int $periodId, string $date): stdClass
    {
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->first();
        if (! $period || $period->closed_at || $date < $period->starts_on || $date > $period->ends_on) {
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
     * @return list<array{ledger_account_id: int, position: int, debit_cents: int, credit_cents: int}>
     */
    public function lines(int $companyId, array $lines): array
    {
        $normalized = [];
        $debit = $credit = 0;
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id')->all();
        foreach ($lines as $index => $line) {
            $accountId = $line['account_id'] ?? null;
            if (! is_scalar($accountId) || ! is_scalar($line['debit'] ?? null) || ! is_scalar($line['credit'] ?? null)) {
                throw ValidationException::withMessages(['accounting' => 'Usa un importe numérico en cada línea.']);
            }
            $d = $this->cents((string) $line['debit']);
            $c = $this->cents((string) $line['credit']);
            if (! in_array((int) $accountId, $accounts) || ($d > 0) === ($c > 0)) {
                throw ValidationException::withMessages(['accounting' => 'Cada línea necesita una cuenta de esta empresa y solo debe o solo haber mayor a cero.']);
            }
            $normalized[] = ['ledger_account_id' => (int) $accountId, 'position' => $index + 1, 'debit_cents' => $d, 'credit_cents' => $c];
            $debit += $d;
            $credit += $c;
        }
        if (count($normalized) < 2 || $debit === 0 || $debit !== $credit) {
            throw ValidationException::withMessages(['accounting' => 'El asiento necesita al menos dos líneas y el debe debe ser igual al haber.']);
        }

        return $normalized;
    }

    public function post(int $companyId, stdClass $entry, User $user): void
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['accounting' => 'Este asiento ya fue procesado.']);
        }
        $period = $this->openPeriod($companyId, $entry->accounting_period_id, $entry->entry_date);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $accountIds = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id');
        if ($lines->count() < 2 || $lines->sum('debit_cents') <= 0 || $lines->sum('debit_cents') !== $lines->sum('credit_cents') || $lines->contains(fn ($l) => ! $accountIds->contains($l->ledger_account_id) || $l->debit_cents < 0 || $l->credit_cents < 0 || ($l->debit_cents > 0) === ($l->credit_cents > 0))) {
            throw ValidationException::withMessages(['accounting' => 'El asiento no supera la validación de integridad.']);
        }
        $number = (int) DB::table('journal_entries')->where('accounting_period_id', $period->id)->max('number') + 1;
        DB::table('journal_entries')->where('id', $entry->id)->update(['number' => $number, 'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $details */
    public function audit(Organization $organization, User $user, string $action, string $table, int $id, array $details = []): void
    {
        DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'action' => $action, 'auditable_type' => $table, 'auditable_id' => $id, 'new_values' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'created_at' => now()]);
    }
}
