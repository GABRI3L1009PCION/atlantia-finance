<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Accounting\AccountingDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether a company period can be sent to review. Blockers stop the submission; warnings are shown but let it
 * through (the reviewer handles them). A check that cannot be computed is itself a blocker, never a silent pass.
 *
 * @phpstan-type Finding array{key: string, label: string, detail: string, destination: string|null}
 * @phpstan-type Readiness array{ready: bool, blockers: list<Finding>, warnings: list<Finding>}
 */
class CompanyPeriodReadinessService
{
    public function __construct(private AccountingDashboardService $dashboard) {}

    /** @return Readiness */
    public function check(int $organizationId, int $companyId, int $periodId): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first(['public_id']);
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->first(['id', 'name', 'starts_on', 'ends_on', 'closed_at', 'status']);
        abort_if($company === null || $period === null, 404);
        $base = '/accounting/companies/'.$company->public_id;
        $blockers = [];
        $warnings = [];
        $add = function (bool $isProblem, bool $blocking, string $key, string $label, string $detail, ?string $destination) use (&$blockers, &$warnings): void {
            if ($isProblem) {
                $blocking ? $blockers[] = compact('key', 'label', 'detail', 'destination') : $warnings[] = compact('key', 'label', 'detail', 'destination');
            }
        };

        $add($period->closed_at !== null || $period->status === 'closed', true, 'period_closed', 'Período cerrado', 'El período '.$period->name.' ya está cerrado.', null);

        try {
            $openTasks = DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('company_id', $companyId)->where('accounting_period_id', $periodId)
                ->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->where('module', '!=', 'closing')->count();
            $add($openTasks > 0, true, 'open_tasks', 'Tareas pendientes', $openTasks.' tarea(s) del período sin terminar.', '/assistant/tasks?company_id='.$companyId);

            $observations = DB::table('review_observations')->where('review_observations.organization_id', $organizationId)->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('accounting_period_id', $periodId)->orWhereNull('accounting_period_id'))->whereIn('status', ReviewObservationService::CORRECTABLE)->count();
            $add($observations > 0, true, 'observations', 'Observaciones sin corregir', $observations.' observación(es) abiertas.', '/assistant/observations?company_id='.$companyId);

            $documents = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('company_id', $companyId)->where('type', 'document')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();
            $add($documents > 0, true, 'documents', 'Documentos críticos faltantes', $documents.' solicitud(es) de documentos sin recibir.', '/assistant/documents?company_id='.$companyId);

            $critical = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('company_id', $companyId)->whereIn('status', ['open', 'in_progress', 'blocked'])
                ->where('type', '!=', 'document')->where(fn ($query) => $query->where('severity', 'critical')->orWhere('blocks_period', true))->count();
            $add($critical > 0, true, 'incidents', 'Incidencias críticas', $critical.' incidencia(s) que bloquean el período.', '/assistant/incidents');

            foreach (DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->get(['id', 'bank_name', 'account_name']) as $account) {
                $pending = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('reconciliation_status', 'pending')->whereBetween('transaction_date', [$period->starts_on, $period->ends_on])->count();
                $add($pending > 0, true, 'bank:'.$account->id, $account->bank_name.' sin conciliar', $pending.' movimiento(s) de '.$account->account_name.' sin conciliar.', $base.'/banks');
            }

            $drafts = DB::table('journal_entries')->where('accounting_period_id', $periodId)->whereIn('status', ['draft', 'rejected'])->count();
            $add($drafts > 0, true, 'draft_entries', 'Asientos sin enviar', $drafts.' asiento(s) en borrador o rechazados.', $base.'#libro-diario');

            $data = $this->dashboard->forCompany($companyId, $periodId);
            $add(! $data['trial_balance']['balanced'], true, 'trial_balance', 'Balance descuadrado', 'El debe y el haber del período no cuadran.', $base.'#balance-comprobacion');
            $unposted = collect($data['pending']);
            $add($unposted->where('origin', 'Ventas')->isNotEmpty(), true, 'sales', 'Ventas sin contabilizar', $unposted->where('origin', 'Ventas')->count().' venta(s) sin asiento.', $base.'/sales');
            $add($unposted->where('origin', 'Compras')->isNotEmpty(), true, 'purchases', 'Compras sin contabilizar', $unposted->where('origin', 'Compras')->count().' compra(s) sin asiento.', $base.'/purchases');
            $add((int) $data['summary']['unsupported_entries'] > 0, false, 'support', 'Asientos sin soporte', $data['summary']['unsupported_entries'].' asiento(s) sin documento soporte.', $base.'#libro-diario');

            $end = Carbon::parse($period->ends_on);
            $vat = DB::table('vat_book_periods')->where('company_id', $companyId)->where('year', $end->year)->where('month', $end->month)->value('status');
            $add($vat !== 'closed', false, 'vat_book', 'Libro IVA pendiente', 'El Libro IVA del mes sigue abierto; lo cierra la revisión fiscal.', $base.'/vat-books');
        } catch (Throwable $exception) {
            Log::warning('Readiness check failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);
            $blockers[] = ['key' => 'error', 'label' => 'Verificación no disponible', 'detail' => 'No fue posible verificar la empresa; inténtalo de nuevo.', 'destination' => null];
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings];
    }
}
