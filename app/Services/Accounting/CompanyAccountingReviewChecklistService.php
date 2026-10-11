<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Services\Operations\ReviewObservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Review checklist of a company period for the accountant in charge. Every check is computed from real data and returns
 * PASSED, WARNING, BLOCKED, NOT_APPLICABLE or ERROR; a check that fails to compute is reported as ERROR, never as zero.
 *
 * @phpstan-type ReviewCheck array{key: string, group: string, label: string, status: string, detail: string, destination: string|null}
 */
class CompanyAccountingReviewChecklistService
{
    public const PASSED = 'PASSED';

    public const WARNING = 'WARNING';

    public const BLOCKED = 'BLOCKED';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const ERROR = 'ERROR';

    public const LABELS = [self::PASSED => 'Correcto', self::WARNING => 'Advertencia', self::BLOCKED => 'Bloqueante', self::NOT_APPLICABLE => 'No aplica', self::ERROR => 'Error'];

    private const CLOSE_STATUS = ['passed' => self::PASSED, 'warning' => self::WARNING, 'blocked' => self::BLOCKED];

    public function __construct(
        private AccountingPeriodCloseChecklistService $closeChecklist,
        private AccountingDashboardService $dashboard,
    ) {}

    /**
     * @return array{checks: list<ReviewCheck>, summary: array<string, int>, ready_for_approval: bool}
     */
    public function forPeriod(int $organizationId, int $companyId, int $periodId): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first(['public_id']);
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->first(['id', 'starts_on', 'ends_on']);
        abort_if($company === null || $period === null, 404);
        $base = '/accounting/companies/'.$company->public_id;
        $checks = [];

        $closeChecks = $this->attempt('accounting', 'Contabilidad del período', function () use ($companyId, $periodId, $base): array {
            return array_map(fn (array $check): array => [
                'key' => $check['key'],
                'group' => 'Contabilidad',
                'label' => $check['label'],
                'status' => self::CLOSE_STATUS[$check['status']] ?? self::WARNING,
                'detail' => $check['count'] > 0 ? $check['message'].' ('.$check['count'].')' : $check['message'],
                'destination' => $check['destination'] === 'libro-diario' ? $base : $base.'/dashboard',
            ], $this->closeChecklist->forPeriod($companyId, $periodId));
        });
        array_push($checks, ...$closeChecks);

        $reconciliations = $this->attempt('reconciliations', 'Conciliaciones con el mayor', function () use ($companyId, $periodId, $base): array {
            $rows = [];
            foreach ($this->dashboard->forCompany($companyId, $periodId)['reconciliations'] as $row) {
                $label = (string) $row['label'];
                if ($row['auxiliary_cents'] === null) {
                    $rows[] = [
                        'key' => 'reconciliation_'.mb_strtolower(str_replace(' ', '_', $label)),
                        'group' => 'Conciliaciones',
                        'label' => $label.' contra mayor',
                        'status' => (int) $row['ledger_cents'] === 0 ? self::NOT_APPLICABLE : self::WARNING,
                        'detail' => (int) $row['ledger_cents'] === 0 ? 'La empresa no registra '.mb_strtolower($label).'.' : 'Hay saldo en el mayor pero no existe auxiliar para compararlo.',
                        'destination' => null,
                    ];

                    continue;
                }
                $difference = (int) $row['difference_cents'];
                $rows[] = [
                    'key' => 'reconciliation_'.mb_strtolower($label),
                    'group' => 'Conciliaciones',
                    'label' => $label.' contra mayor',
                    'status' => $difference === 0 ? self::PASSED : self::WARNING,
                    'detail' => $difference === 0 ? 'Auxiliar y mayor coinciden.' : 'Diferencia de Q '.number_format($difference / 100, 2).'; debe explicarse o corregirse, nunca ajustarse automáticamente.',
                    'destination' => $base.'/dashboard',
                ];
            }

            return $rows;
        });
        array_push($checks, ...$reconciliations);

        $checks[] = $this->single('vat_book', 'Fiscal', 'Libro de IVA del mes', function () use ($companyId, $period, $base): array {
            $end = Carbon::parse($period->ends_on);
            $status = DB::table('vat_book_periods')->where('company_id', $companyId)->where('year', $end->year)->where('month', $end->month)->value('status');

            return $status === 'closed'
                ? [self::PASSED, 'Libro IVA cerrado y cuadrado con el mayor.', $base.'/vat-books']
                : [self::WARNING, 'El Libro IVA del mes sigue abierto.', $base.'/vat-books'];
        });

        $checks[] = $this->single('payroll', 'Nómina', 'Nómina del período', function () use ($companyId, $periodId): array {
            $hasPayroll = DB::table('work_tasks')->where('company_id', $companyId)->where('module', 'payroll')->whereNull('deleted_at')->exists();
            if (! $hasPayroll) {
                return [self::NOT_APPLICABLE, 'La empresa no tiene nómina a cargo de la oficina.', null];
            }
            $open = DB::table('work_tasks')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->where('module', 'payroll')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count();

            return $open === 0 ? [self::PASSED, 'Nómina procesada.', null] : [self::WARNING, $open.' tarea(s) de nómina abiertas.', null];
        });

        $checks[] = $this->single('observations', 'Revisión', 'Observaciones resueltas', function () use ($organizationId, $companyId, $periodId): array {
            $active = DB::table('review_observations')->where('review_observations.organization_id', $organizationId)->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('accounting_period_id', $periodId)->orWhereNull('accounting_period_id'))->whereIn('status', ReviewObservationService::ACTIVE)->count();

            return $active === 0 ? [self::PASSED, 'Sin observaciones pendientes.', '/accountant/observations'] : [self::BLOCKED, $active.' observación(es) sin resolver.', '/accountant/observations?company_id='.$companyId];
        });

        $checks[] = $this->single('critical_incidents', 'Revisión', 'Incidencias críticas', function () use ($organizationId, $companyId): array {
            $critical = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('company_id', $companyId)->whereIn('status', ['open', 'in_progress', 'blocked'])
                ->where(fn ($query) => $query->where('severity', 'critical')->orWhere('blocks_period', true))->count();

            return $critical === 0 ? [self::PASSED, 'Sin incidencias que bloqueen.', null] : [self::BLOCKED, $critical.' incidencia(s) críticas abiertas.', '/accountant/incidents'];
        });

        $checks[] = $this->single('open_tasks', 'Revisión', 'Tareas del período', function () use ($companyId, $periodId): array {
            $open = DB::table('work_tasks')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->where('module', '!=', 'closing')->count();

            return $open === 0 ? [self::PASSED, 'Todas las tareas del período están terminadas.', null] : [self::WARNING, $open.' tarea(s) abiertas.', '/work/dashboard'];
        });

        $summary = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return ['checks' => $checks, 'summary' => $summary, 'ready_for_approval' => $summary[self::BLOCKED] === 0 && $summary[self::ERROR] === 0];
    }

    /**
     * @param  callable(): list<ReviewCheck>  $callback
     * @return list<ReviewCheck>
     */
    private function attempt(string $key, string $label, callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Review checklist failed', ['check' => $key, 'error' => $exception->getMessage()]);

            return [['key' => $key, 'group' => 'Sistema', 'label' => $label, 'status' => self::ERROR, 'detail' => 'No fue posible calcular esta verificación.', 'destination' => null]];
        }
    }

    /**
     * @param  callable(): array{0: string, 1: string, 2: string|null}  $callback
     * @return ReviewCheck
     */
    private function single(string $key, string $group, string $label, callable $callback): array
    {
        try {
            [$status, $detail, $destination] = $callback();

            return ['key' => $key, 'group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail, 'destination' => $destination];
        } catch (Throwable $exception) {
            Log::warning('Review checklist failed', ['check' => $key, 'error' => $exception->getMessage()]);

            return ['key' => $key, 'group' => $group, 'label' => $label, 'status' => self::ERROR, 'detail' => 'No fue posible calcular esta verificación.', 'destination' => null];
        }
    }
}
