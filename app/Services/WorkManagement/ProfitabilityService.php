<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Profitability of each client of the firm in a month: the fee agreed in its contract against the hours the team really
 * spent and what those hours cost internally. The margin is before overhead and is not the net profit, and an agreed fee
 * is not money collected: collections live in the firm's revenue screen.
 */
class ProfitabilityService
{
    /** A client over its budgeted hours by more than this share is flagged. */
    public const OVERRUN_THRESHOLD = 10.0;

    public function __construct(private AccountingService $accounting) {}

    /**
     * @return array<string, mixed>
     */
    public function forMonth(Organization $organization, User $user, string $month): array
    {
        $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $last = $first->copy()->endOfMonth();
        $companies = $this->accounting->companies($organization, $user)->orderBy('companies.name')->get(['companies.id', 'companies.public_id', 'companies.name', 'companies.office_id', 'offices.name as office_name']);
        $ids = $companies->pluck('id')->all();
        $contracts = DB::table('crm_contracts')->whereIn('company_id', $ids === [] ? [-1] : $ids)->where('status', 'active')->where('starts_on', '<=', $last->toDateString())
            ->selectRaw('company_id, CAST(SUM(monthly_fee_cents) AS BIGINT) as fee, CAST(SUM(budget_minutes) AS BIGINT) as budget')->groupBy('company_id')->get()->keyBy('company_id');
        $entries = DB::table('time_entries as t')->join('users as u', 'u.id', '=', 't.user_id')->whereIn('t.company_id', $ids === [] ? [-1] : $ids)->whereBetween('t.work_date', [$first->toDateString(), $last->toDateString()])
            ->selectRaw('t.company_id, u.name as person, CAST(SUM(t.minutes) AS BIGINT) as minutes, CAST(SUM(ROUND(t.minutes * t.cost_per_hour_cents / 60.0)) AS BIGINT) as cost')->groupBy('t.company_id', 'u.name')->get()->groupBy('company_id');

        $rows = $companies->map(function (object $company) use ($contracts, $entries): array {
            $contract = $contracts->get($company->id);
            $fee = (int) ($contract->fee ?? 0);
            $budget = (int) ($contract->budget ?? 0);
            $people = collect($entries->get($company->id, []))->map(fn (stdClass $row): array => ['name' => (string) $row->person, 'minutes' => (int) $row->minutes, 'cost_cents' => (int) $row->cost])->sortByDesc('minutes')->values();
            $minutes = (int) $people->sum('minutes');
            $cost = (int) $people->sum('cost_cents');
            $deviation = $budget > 0 ? $minutes - $budget : null;
            $deviationPct = $budget > 0 ? round(($minutes - $budget) / $budget * 100, 1) : null;
            $margin = $fee - $cost;

            return ['company_id' => (int) $company->id, 'public_id' => (string) $company->public_id, 'name' => (string) $company->name, 'office' => (string) $company->office_name, 'has_contract' => $contract !== null,
                'fee_cents' => $fee, 'budget_minutes' => $budget, 'minutes' => $minutes, 'deviation_minutes' => $deviation, 'deviation_pct' => $deviationPct, 'cost_cents' => $cost, 'margin_cents' => $margin,
                'margin_pct' => $fee > 0 ? round($margin / $fee * 100, 1) : null, 'people' => $people->all(),
                'flag' => $contract === null ? 'no_contract' : ($margin < 0 ? 'loss' : ($deviationPct !== null && $deviationPct > self::OVERRUN_THRESHOLD ? 'over_budget' : 'ok'))];
        });

        $fee = (int) $rows->sum('fee_cents');
        $cost = (int) $rows->sum('cost_cents');

        return ['month' => $first->format('Y-m'), 'rows' => $rows->all(), 'totals' => ['fee_cents' => $fee, 'budget_minutes' => (int) $rows->sum('budget_minutes'), 'minutes' => (int) $rows->sum('minutes'), 'cost_cents' => $cost,
            'margin_cents' => $fee - $cost, 'margin_pct' => $fee > 0 ? round(($fee - $cost) / $fee * 100, 1) : null],
            'offices' => DB::table('offices')->where('organization_id', $organization->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'hourly_cost_cents'])->map(fn (object $o): array => ['id' => (int) $o->id, 'name' => (string) $o->name, 'hourly_cost_cents' => (int) $o->hourly_cost_cents])->all()];
    }

    /** Sets the hours a client's contract budgets per month. */
    public function setBudget(Organization $organization, User $actor, int $companyId, string $hours): void
    {
        $minutes = $this->minutes($hours);
        $contract = DB::table('crm_contracts')->where('organization_id', $organization->id)->where('company_id', $companyId)->where('status', 'active')->orderByDesc('starts_on')->first();
        if ($contract === null) {
            throw ValidationException::withMessages(['hours' => 'Esta empresa no tiene un contrato activo. Crea el contrato con su honorario en Clientes.']);
        }
        DB::table('crm_contracts')->where('id', $contract->id)->update(['budget_minutes' => $minutes, 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'profitability.budget_set', 'crm_contracts', (int) $contract->id, ['company_id' => $companyId, 'budget_minutes' => $minutes]);
    }

    /** Sets what one hour of work costs the office. */
    public function setHourlyCost(Organization $organization, User $actor, int $officeId, string $amount): void
    {
        $clean = str_replace([',', ' ', 'Q'], '', trim($amount));
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $clean) || (float) $clean <= 0 || (float) $clean > 100000) {
            throw ValidationException::withMessages(['cost' => 'Escribe un costo por hora válido, por ejemplo 50.00.']);
        }
        $updated = DB::table('offices')->where('organization_id', $organization->id)->where('id', $officeId)->whereNull('deleted_at')->update(['hourly_cost_cents' => (int) round((float) $clean * 100), 'updated_at' => now()]);
        abort_if($updated === 0, 404);
        $this->accounting->audit($organization, $actor, 'profitability.hourly_cost_set', 'offices', $officeId, ['hourly_cost_cents' => (int) round((float) $clean * 100)], $officeId);
    }

    private function minutes(string $hours): int
    {
        $clean = str_replace(',', '.', trim($hours));
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $clean) || (float) $clean > 744) {
            throw ValidationException::withMessages(['hours' => 'Escribe las horas del mes, por ejemplo 15.']);
        }

        return (int) round((float) $clean * 60);
    }
}
