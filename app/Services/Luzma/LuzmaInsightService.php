<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use App\Services\Payables\PayableService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\Payroll\PayrollLedgerReconciliationService;
use App\Services\Receivables\ReceivableService;
use App\Services\Receivables\ReceivablesLedgerReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * "Luzma detectó...": insights found by deterministic detectors over real data (no model involved in detecting).
 * Each one has a stable key (no duplicates), a hash of its data (updated when the situation changes), resolves
 * itself when the source is fixed and becomes stale when it has not been re-verified recently.
 */
class LuzmaInsightService
{
    public const LABELS = ['alert' => 'ALERTA', 'suggestion' => 'SUGERENCIA', 'difference' => 'DIFERENCIA', 'anomaly' => 'POSIBLE ANOMALÍA'];

    /** Permissions that let a user see the insights of each module. */
    public const MODULE_PERMISSIONS = [
        'payables' => ['payables.dashboard.view', 'purchases.view'],
        'receivables' => ['receivables.dashboard.view', 'sales.view'],
        'payroll' => ['payroll.dashboard.view'],
        'audit_desk' => ['audit.dashboard.view'],
        'treasury' => ['treasury.dashboard.view', 'banks.view'],
        'tax' => ['fiscal.dashboard.view', 'fiscal.view'],
        'operations' => ['operations.view', 'tasks.view_all', 'office.workload.view'],
    ];

    private const STALE_AFTER_HOURS = 24;

    public function __construct(private LuzmaAuditService $audit) {}

    /**
     * Runs every detector for the tenant and reconciles the stored insights with what is true now.
     *
     * @return array{created: int, updated: int, resolved: int}
     */
    public function refresh(Organization $organization): array
    {
        $companyIds = array_values(array_map('intval', DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')->all()));
        $detected = [];
        foreach (['ledger', 'banks', 'fiscal', 'promises', 'incidents', 'duplicates', 'cash', 'taxesSoon', 'salesDrop', 'expenseJump', 'fxMove'] as $detector) {
            try {
                array_push($detected, ...$this->{$detector}($companyIds));
            } catch (Throwable $exception) {
                // A failing detector must not resolve the insights it owns: they stay as they are.
                Log::warning('Luzma detector failed', ['detector' => $detector, 'error' => $exception->getMessage()]);

                return ['created' => 0, 'updated' => 0, 'resolved' => 0];
            }
        }
        $created = 0;
        $updated = 0;
        $keys = [];
        foreach ($detected as $insight) {
            $keys[] = $insight['key'];
            $hash = hash('sha256', json_encode($insight['data'], JSON_THROW_ON_ERROR));
            $existing = DB::table('luzma_insights')->where('luzma_insights.organization_id', $organization->id)->where('insight_key', $insight['key'])->first(['id', 'status', 'version_hash']);
            $values = ['company_id' => $insight['company_id'], 'module' => $insight['module'], 'source_type' => $insight['source_type'], 'source_id' => $insight['source_id'], 'severity' => $insight['severity'],
                'label' => $insight['label'], 'title' => mb_substr($insight['title'], 0, 200), 'body' => $insight['body'], 'data' => json_encode($insight['data'], JSON_THROW_ON_ERROR), 'version_hash' => $hash,
                'destination' => $insight['destination'], 'updated_at' => now()];
            if ($existing === null) {
                DB::table('luzma_insights')->insert([...$values, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'insight_key' => $insight['key'], 'status' => 'open', 'detected_at' => now(), 'created_at' => now()]);
                $created++;
            } elseif ($existing->version_hash !== $hash || in_array($existing->status, ['resolved', 'stale'], true)) {
                // A changed situation (or one that came back) is shown again; a dismissed one stays dismissed unless it changed.
                $status = $existing->status === 'dismissed' && $existing->version_hash === $hash ? 'dismissed' : 'open';
                DB::table('luzma_insights')->where('id', $existing->id)->update([...$values, 'status' => $status, 'resolved_at' => null, 'detected_at' => $status === 'open' && $existing->version_hash !== $hash ? now() : DB::raw('detected_at')]);
                $updated++;
            } else {
                DB::table('luzma_insights')->where('id', $existing->id)->update(['updated_at' => now()]);
            }
        }
        $resolved = DB::table('luzma_insights')->where('luzma_insights.organization_id', $organization->id)->whereIn('status', ['open', 'acknowledged', 'stale'])
            ->whereNotIn('insight_key', $keys === [] ? ['-'] : $keys)->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
        if ($created > 0) {
            DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'action' => 'luzma.insight.generated', 'auditable_type' => 'luzma_insights', 'new_values' => json_encode(['created' => $created], JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
        }

        return ['created' => $created, 'updated' => $updated, 'resolved' => $resolved];
    }

    /**
     * Insights the user may see (accessible companies and module permissions), most severe first.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(LuzmaContext $context, int $limit = 20, ?string $module = null): array
    {
        DB::table('luzma_insights')->where('luzma_insights.organization_id', $context->organization->id)->whereIn('status', ['open', 'acknowledged'])
            ->where('updated_at', '<', now()->subHours(self::STALE_AFTER_HOURS))->update(['status' => 'stale']);
        $modules = array_keys(array_filter(self::MODULE_PERMISSIONS, fn (array $permissions): bool => $context->canAny($permissions)));
        if ($modules === []) {
            return [];
        }

        return array_values(DB::table('luzma_insights')->leftJoin('companies', 'companies.id', '=', 'luzma_insights.company_id')->where('luzma_insights.organization_id', $context->organization->id)
            ->whereIn('luzma_insights.status', ['open', 'acknowledged'])->whereIn('luzma_insights.module', $module === null ? $modules : array_intersect($modules, [$module]))
            ->whereIn('luzma_insights.company_id', $context->scopeCompanyIds() === [] ? [-1] : $context->scopeCompanyIds())
            ->orderByRaw("CASE luzma_insights.severity WHEN 'critical' THEN 0 WHEN 'high_priority' THEN 1 WHEN 'warning' THEN 2 WHEN 'recommendation' THEN 3 ELSE 4 END")->orderByDesc('luzma_insights.detected_at')->limit($limit)
            ->get(['luzma_insights.*', 'companies.name as company_name'])->map(fn (stdClass $row): array => [
                'id' => (string) $row->public_id, 'module' => (string) $row->module, 'severity' => (string) $row->severity, 'label' => self::LABELS[$row->label] ?? (string) $row->label,
                'title' => (string) $row->title, 'body' => (string) $row->body, 'company' => $row->company_name, 'status' => (string) $row->status, 'destination' => $row->destination,
                'detected_at' => Carbon::parse($row->detected_at)->toIso8601String(),
            ])->all());
    }

    public function transition(LuzmaContext $context, User $user, string $publicId, string $action): void
    {
        $row = DB::table('luzma_insights')->where('luzma_insights.organization_id', $context->organization->id)->where('public_id', $publicId)->first(['id', 'company_id', 'module']);
        abort_if($row === null || ! in_array((int) $row->company_id, $context->companyIds, true) || ! $context->canAny(self::MODULE_PERMISSIONS[$row->module] ?? ['__none']), 404);
        $changes = match ($action) {
            'acknowledge' => ['status' => 'acknowledged', 'acknowledged_by' => $user->id, 'acknowledged_at' => now()],
            'dismiss' => ['status' => 'dismissed', 'acknowledged_by' => $user->id, 'acknowledged_at' => now()],
            default => abort(404),
        };
        DB::table('luzma_insights')->where('id', $row->id)->update([...$changes, 'updated_at' => now()]);
        $this->audit->record($context->organization, $user, 'luzma.insight.'.$action, 'luzma_insights', (int) $row->id);
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function ledger(array $companyIds): array
    {
        $insights = [];
        foreach (DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->get(['id', 'name']) as $company) {
            foreach ([['payables', 'CxP', app(PayablesLedgerReconciliationService::class)->compute((int) $company->id, app(PayableService::class)->today((int) $company->id))],
                ['receivables', 'CxC', app(ReceivablesLedgerReconciliationService::class)->compute((int) $company->id, app(ReceivableService::class)->today((int) $company->id))],
                ['payroll', 'La nómina', app(PayrollLedgerReconciliationService::class)->compute((int) $company->id, now()->toDateString())]] as [$module, $area, $result]) {
                if ($result['status'] !== 'difference') {
                    continue;
                }
                $insights[] = ['key' => $module.'_ledger:'.$company->id, 'company_id' => (int) $company->id, 'module' => $module, 'source_type' => 'companies', 'source_id' => (int) $company->id,
                    'severity' => 'high_priority', 'label' => 'difference', 'title' => $area.' no coincide con el Mayor en '.$company->name,
                    'body' => 'Diferencia de Q '.number_format((int) $result['difference_cents'] / 100, 2).' con '.count($result['items']).' partida(s) por investigar. Se corrige en el origen; no se "cuadra".',
                    'data' => ['difference_cents' => $result['difference_cents'], 'items' => count($result['items'])], 'destination' => '/'.$module.'/reconciliation?company_id='.$company->id];
            }
        }

        return $insights;
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function banks(array $companyIds): array
    {
        return array_values(DB::table('bank_accounts')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')->whereIn('bank_accounts.company_id', $companyIds === [] ? [-1] : $companyIds)->where('bank_accounts.status', 'active')
            ->select('bank_accounts.id', 'bank_accounts.company_id', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'companies.name as company')
            ->selectSub(fn ($query) => $query->selectRaw('CAST(COUNT(*) AS BIGINT)')->from('bank_transactions')->whereColumn('bank_transactions.bank_account_id', 'bank_accounts.id')
                ->where('bank_transactions.reconciliation_status', '!=', 'matched')->where('bank_transactions.transaction_date', '<', now()->subDays(7)->toDateString()), 'old_pending')
            ->get()->filter(fn (stdClass $row): bool => (int) $row->old_pending > 0)
            ->map(fn (stdClass $row): array => ['key' => 'bank_pending:'.$row->id, 'company_id' => (int) $row->company_id, 'module' => 'treasury', 'source_type' => 'bank_accounts', 'source_id' => (int) $row->id,
                'severity' => 'warning', 'label' => 'alert', 'title' => $row->old_pending.' movimiento(s) de '.$row->bank_name.' ****'.$row->last_four.' sin conciliar hace más de 7 días',
                'body' => $row->company.': identifícalos o clasifícalos para que la conciliación del período pueda cerrarse.', 'data' => ['old_pending' => (int) $row->old_pending], 'destination' => '/treasury/movements'])
            ->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function fiscal(array $companyIds): array
    {
        return array_values(DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')->whereIn('fiscal_obligations.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('fiscal_obligations.status', LuzmaReminderConditionService::FISCAL_OPEN)->where('fiscal_obligations.due_on', '<', now()->toDateString())
            ->get(['fiscal_obligations.id', 'fiscal_obligations.company_id', 'fiscal_obligations.name', 'fiscal_obligations.reference_period', 'fiscal_obligations.due_on', 'fiscal_obligations.status', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['key' => 'fiscal_overdue:'.$row->id, 'company_id' => (int) $row->company_id, 'module' => 'tax', 'source_type' => 'fiscal_obligations', 'source_id' => (int) $row->id,
                'severity' => 'critical', 'label' => 'alert', 'title' => $row->name.' '.$row->reference_period.' de '.$row->company.' está vencida',
                'body' => 'La fecha límite fue el '.$row->due_on.' y la obligación sigue abierta.', 'data' => ['status' => (string) $row->status], 'destination' => '/tax/obligations/'.$row->id])
            ->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function promises(array $companyIds): array
    {
        return array_values(DB::table('promises_to_pay')->join('companies', 'companies.id', '=', 'promises_to_pay.company_id')->join('sales_customers', 'sales_customers.id', '=', 'promises_to_pay.sales_customer_id')
            ->whereIn('promises_to_pay.company_id', $companyIds === [] ? [-1] : $companyIds)->where('promises_to_pay.status', 'broken')->where('promises_to_pay.updated_at', '>=', now()->subDays(7))
            ->get(['promises_to_pay.id', 'promises_to_pay.company_id', 'promises_to_pay.amount_cents', 'promises_to_pay.promised_date', 'sales_customers.id as customer_id', 'sales_customers.name as customer', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['key' => 'promise_broken:'.$row->id, 'company_id' => (int) $row->company_id, 'module' => 'receivables', 'source_type' => 'promises_to_pay', 'source_id' => (int) $row->id,
                'severity' => 'warning', 'label' => 'alert', 'title' => $row->customer.' incumplió su promesa de pago',
                'body' => $row->company.': Q '.number_format((int) $row->amount_cents / 100, 2).' prometidos para el '.$row->promised_date.'. Conviene registrar una nueva gestión.', 'data' => ['amount_cents' => (int) $row->amount_cents],
                'destination' => '/receivables/customers/'.$row->customer_id])
            ->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function incidents(array $companyIds): array
    {
        return array_values(DB::table('operational_incidents')->join('companies', 'companies.id', '=', 'operational_incidents.company_id')->whereIn('operational_incidents.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('operational_incidents.severity', 'high')->whereIn('operational_incidents.status', ['open', 'in_progress', 'blocked'])->where('operational_incidents.created_at', '<', now()->subDays(3))
            ->where(fn ($query) => $query->whereNull('operational_incidents.automation_key')->orWhere(fn ($q) => $q->where('operational_incidents.automation_key', 'not like', '%_difference:%')->where('operational_incidents.automation_key', 'not like', 'duplicate_invoice:%')))
            ->get(['operational_incidents.id', 'operational_incidents.company_id', 'operational_incidents.title', 'operational_incidents.status', 'operational_incidents.created_at', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['key' => 'incident_stalled:'.$row->id, 'company_id' => (int) $row->company_id, 'module' => 'operations', 'source_type' => 'operational_incidents', 'source_id' => (int) $row->id,
                'severity' => 'high_priority', 'label' => 'alert', 'title' => 'Incidencia de severidad alta sin resolver hace más de 3 días',
                'body' => $row->company.': '.$row->title.'.', 'data' => ['status' => (string) $row->status], 'destination' => '/operations/incidents?incident='.$row->id])
            ->all());
    }

    /**
     * Cash or bank accounts of the ledger with a negative balance: money that cannot be below zero.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function cash(array $companyIds): array
    {
        return array_values(DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->join('companies', 'companies.id', '=', 'p.company_id')->whereIn('p.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('e.status', ['posted', 'reversed'])->whereIn('a.code', ['1101', '1102', '1104', '1106', '1115'])
            ->groupBy('p.company_id', 'companies.name', 'a.id', 'a.code', 'a.name')->havingRaw('SUM(l.debit_cents) - SUM(l.credit_cents) < 0')
            ->selectRaw('p.company_id, companies.name AS company, a.id AS account_id, a.code, a.name, CAST(SUM(l.debit_cents) - SUM(l.credit_cents) AS BIGINT) AS balance')->get()
            ->map(fn (stdClass $row): array => ['key' => 'cash_negative:'.$row->account_id, 'company_id' => (int) $row->company_id, 'module' => 'treasury', 'source_type' => 'ledger_accounts', 'source_id' => (int) $row->account_id,
                'severity' => 'high_priority', 'label' => 'anomaly', 'title' => $row->name.' está en negativo en '.$row->company,
                'body' => 'La cuenta '.$row->code.' tiene saldo de -Q '.number_format(abs((int) $row->balance) / 100, 2).'. Puede faltar registrar un depósito o un ingreso, o sobrar un pago.',
                'data' => ['balance' => (int) $row->balance], 'destination' => '/accounting/companies/'.DB::table('companies')->where('id', $row->company_id)->value('public_id').'/banks'])
            ->all());
    }

    /**
     * Tax obligations due in the next 5 days that are still open.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function taxesSoon(array $companyIds): array
    {
        $today = now()->startOfDay();

        return array_values(DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')->whereIn('fiscal_obligations.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('fiscal_obligations.status', LuzmaReminderConditionService::FISCAL_OPEN)->whereBetween('fiscal_obligations.due_on', [$today->toDateString(), $today->copy()->addDays(5)->toDateString()])
            ->get(['fiscal_obligations.id', 'fiscal_obligations.company_id', 'fiscal_obligations.name', 'fiscal_obligations.reference_period', 'fiscal_obligations.due_on', 'companies.name as company'])
            ->map(function (stdClass $row) use ($today): array {
                $days = (int) $today->diffInDays(Carbon::parse($row->due_on));

                return ['key' => 'fiscal_soon:'.$row->id, 'company_id' => (int) $row->company_id, 'module' => 'tax', 'source_type' => 'fiscal_obligations', 'source_id' => (int) $row->id,
                    'severity' => 'warning', 'label' => 'alert', 'title' => $row->name.' '.$row->reference_period.' de '.$row->company.' vence en '.$days.' día(s)',
                    'body' => 'Fecha límite '.Carbon::parse($row->due_on)->format('d/m/Y').'. Prepárala con tiempo; pregúntame "¿cuánto IVA pago este mes?" para ver el estimado.', 'data' => ['due_on' => (string) $row->due_on],
                    'destination' => '/tax/obligations/'.$row->id];
            })->all());
    }

    /**
     * Sales of the month running clearly below the same days of the previous month.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function salesDrop(array $companyIds): array
    {
        $today = now();
        if ($today->day < 10) {
            return [];
        }
        $previousTo = $today->copy()->subMonthNoOverflow();
        $rows = DB::table('sales_invoices')->join('companies', 'companies.id', '=', 'sales_invoices.company_id')->whereIn('sales_invoices.company_id', $companyIds === [] ? [-1] : $companyIds)->where('sales_invoices.status', 'issued')
            ->whereBetween('sales_invoices.issued_on', [$previousTo->copy()->startOfMonth()->toDateString(), $today->toDateString()])->groupBy('sales_invoices.company_id', 'companies.name')
            ->selectRaw('sales_invoices.company_id, companies.name AS company')
            ->selectRaw('CAST(SUM(CASE WHEN sales_invoices.issued_on >= ? THEN total_cents ELSE 0 END) AS BIGINT) AS current', [$today->copy()->startOfMonth()->toDateString()])
            ->selectRaw('CAST(SUM(CASE WHEN sales_invoices.issued_on <= ? THEN total_cents ELSE 0 END) AS BIGINT) AS previous', [$previousTo->toDateString()])->get();

        return array_values($rows->filter(fn (stdClass $row): bool => (int) $row->previous >= 1000000 && (int) $row->current < (int) $row->previous * 0.6)
            ->map(fn (stdClass $row): array => ['key' => 'sales_drop:'.$row->company_id.':'.$today->format('Y-m'), 'company_id' => (int) $row->company_id, 'module' => 'receivables', 'source_type' => 'companies', 'source_id' => (int) $row->company_id,
                'severity' => 'recommendation', 'label' => 'suggestion', 'title' => 'Las ventas de '.$row->company.' van por debajo del mes pasado',
                'body' => 'Q '.number_format((int) $row->current / 100, 2).' en lo que va del mes contra Q '.number_format((int) $row->previous / 100, 2).' a la misma fecha del mes anterior. Puede ser estacional o faltar facturas por registrar.',
                'data' => ['current' => (int) $row->current, 'previous' => (int) $row->previous], 'destination' => '/accounting/companies/'.DB::table('companies')->where('id', $row->company_id)->value('public_id').'/sales'])
            ->all());
    }

    /**
     * Expense accounts that this month already doubled their monthly average of the last six months.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function expenseJump(array $companyIds): array
    {
        $start = now()->startOfMonth();
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->join('companies', 'companies.id', '=', 'p.company_id')->whereIn('p.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('a.type', 'expense')->whereIn('e.status', ['posted', 'reversed'])->where('e.kind', '<>', 'closing')->whereBetween('e.entry_date', [$start->copy()->subMonths(6)->toDateString(), now()->toDateString()])
            ->groupBy('p.company_id', 'companies.name', 'a.id', 'a.code', 'a.name')->selectRaw('p.company_id, companies.name AS company, a.id AS account_id, a.code, a.name')
            ->selectRaw('CAST(SUM(CASE WHEN e.entry_date >= ? THEN l.debit_cents - l.credit_cents ELSE 0 END) AS BIGINT) AS current', [$start->toDateString()])
            ->selectRaw('CAST(SUM(CASE WHEN e.entry_date < ? THEN l.debit_cents - l.credit_cents ELSE 0 END) AS BIGINT) AS before', [$start->toDateString()])->get();

        return array_values($rows->filter(fn (stdClass $row): bool => intdiv((int) $row->before, 6) > 0 && (int) $row->current > 2 * intdiv((int) $row->before, 6) && (int) $row->current - intdiv((int) $row->before, 6) >= 100000)
            ->map(fn (stdClass $row): array => ['key' => 'expense_jump:'.$row->account_id.':'.$start->format('Y-m'), 'company_id' => (int) $row->company_id, 'module' => 'payables', 'source_type' => 'ledger_accounts', 'source_id' => (int) $row->account_id,
                'severity' => 'recommendation', 'label' => 'anomaly', 'title' => 'El gasto '.$row->name.' de '.$row->company.' se disparó este mes',
                'body' => 'Va en Q '.number_format((int) $row->current / 100, 2).', más del doble de su promedio mensual de Q '.number_format(intdiv((int) $row->before, 6) / 100, 2).'. Revisa si es puntual o un registro duplicado.',
                'data' => ['current' => (int) $row->current, 'average' => intdiv((int) $row->before, 6)], 'destination' => '/accounting/companies/'.DB::table('companies')->where('id', $row->company_id)->value('public_id').'/financial-statements'])
            ->all());
    }

    /**
     * The official dollar moved 1 % or more in a week: companies that buy or sell in foreign currency should know.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function fxMove(array $companyIds): array
    {
        $latest = DB::table('reference_exchange_rates')->where('currency', 'USD')->where('quote_currency', 'GTQ')->orderByDesc('effective_on')->first(['rate', 'effective_on']);
        if ($latest === null) {
            return [];
        }
        $week = DB::table('reference_exchange_rates')->where('currency', 'USD')->where('quote_currency', 'GTQ')->where('effective_on', '<=', Carbon::parse((string) $latest->effective_on)->subDays(7)->toDateString())->orderByDesc('effective_on')->value('rate');
        $change = $week === null || (float) $week == 0.0 ? 0.0 : ((float) $latest->rate - (float) $week) / (float) $week * 100;
        if (abs($change) < 1) {
            return [];
        }
        $since = now()->subDays(90)->toDateString();
        $foreign = DB::table('sales_invoices')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('currency', '<>', 'GTQ')->where('issued_on', '>=', $since)->distinct()->pluck('company_id')
            ->merge(DB::table('purchase_documents')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('currency', '<>', 'GTQ')->where('document_date', '>=', $since)->distinct()->pluck('company_id'))->unique();

        return array_values(DB::table('companies')->whereIn('id', $foreign->all() === [] ? [-1] : $foreign->all())->get(['id', 'name'])
            ->map(fn (stdClass $company): array => ['key' => 'fx_move:'.$company->id.':'.$latest->effective_on, 'company_id' => (int) $company->id, 'module' => 'treasury', 'source_type' => 'companies', 'source_id' => (int) $company->id,
                'severity' => 'recommendation', 'label' => 'suggestion', 'title' => 'El dólar '.($change > 0 ? 'subió' : 'bajó').' '.number_format(abs($change), 2).' % en una semana',
                'body' => $company->name.' tiene operaciones en moneda extranjera: el tipo de cambio oficial está en Q '.number_format((float) $latest->rate, 5).'. Revisa precios y saldos en dólares.',
                'data' => ['rate' => (float) $latest->rate, 'change' => round($change, 2)], 'destination' => '/accounting/companies/'.DB::table('companies')->where('id', $company->id)->value('public_id').'/fiscal-settings'])
            ->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function duplicates(array $companyIds): array
    {
        return array_values(DB::table('operational_incidents')->join('companies', 'companies.id', '=', 'operational_incidents.company_id')->whereIn('operational_incidents.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('operational_incidents.automation_key', 'like', 'duplicate_invoice:%')->whereIn('operational_incidents.status', ['open', 'in_progress'])
            ->get(['operational_incidents.id', 'operational_incidents.company_id', 'operational_incidents.title', 'operational_incidents.description', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['key' => 'duplicate:'.$row->id, 'company_id' => (int) $row->company_id, 'module' => 'payables', 'source_type' => 'operational_incidents', 'source_id' => (int) $row->id,
                'severity' => 'warning', 'label' => 'anomaly', 'title' => $row->title, 'body' => $row->company.': '.$row->description.' Revísalo antes de pagar; puede no ser un duplicado.',
                'data' => ['incident' => (int) $row->id], 'destination' => '/payables/duplicates'])
            ->all());
    }
}
