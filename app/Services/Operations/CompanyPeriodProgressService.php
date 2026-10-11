<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Accounting\AccountingDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stage-by-stage progress of a company period computed from the real modules (documents, sales, purchases, banks,
 * receivables, payables, fiscal and accounting). Stages the company does not use are NOT_APPLICABLE and do not
 * penalise the progress; a stage that cannot be computed is ERROR and never counts as done.
 *
 * @phpstan-type StageStatus array{key: string, label: string, status: string, detail: string}
 * @phpstan-type CompanyProgress array{stages: list<StageStatus>, progress: int|null, applicable: int, complete: int}
 */
class CompanyPeriodProgressService
{
    public const COMPLETE = 'COMPLETE';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const PENDING = 'PENDING';

    public const BLOCKED = 'BLOCKED';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const ERROR = 'ERROR';

    public const STAGES = ['documents' => 'Documentos', 'sales' => 'Ventas', 'purchases' => 'Compras', 'banks' => 'Bancos', 'receivables' => 'CxC', 'payables' => 'CxP', 'fiscal' => 'Fiscal', 'accounting' => 'Contabilidad'];

    public function __construct(private AccountingDashboardService $dashboard) {}

    /** @return CompanyProgress */
    public function forPeriod(int $organizationId, int $companyId, ?int $periodId): array
    {
        if ($periodId === null) {
            return ['stages' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::STAGES[$key], 'status' => self::NOT_APPLICABLE, 'detail' => 'Sin período abierto.'], array_keys(self::STAGES)), 'progress' => null, 'applicable' => 0, 'complete' => 0];
        }

        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->first(['id', 'starts_on', 'ends_on', 'closed_at']);
        abort_if($period === null, 404);
        try {
            $data = $this->dashboard->forCompany($companyId, $periodId);
        } catch (Throwable $exception) {
            Log::warning('Progress data failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);
            $data = null;
        }
        $tasks = DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('company_id', $companyId)->where('accounting_period_id', $periodId)
            ->whereNull('deleted_at')->where('status', '!=', 'cancelled')->get(['module', 'status']);
        $openTasks = fn (array $modules): int => $tasks->whereIn('module', $modules)->whereNotIn('status', ['completed', 'approved'])->count();
        $from = (string) $period->starts_on;
        $to = (string) $period->ends_on;

        $stages = [
            $this->stage('documents', function () use ($organizationId, $companyId, $openTasks): array {
                $requests = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('company_id', $companyId)->where('type', 'document')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();
                $files = DB::table('operational_record_files')->where('company_id', $companyId)->count() + DB::table('purchase_document_files')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_document_files.purchase_document_id')->where('purchase_documents.company_id', $companyId)->count();

                return match (true) {
                    $requests > 0 => [self::PENDING, $requests.' documento(s) solicitados sin recibir.'],
                    $openTasks(['documents']) > 0 => [self::IN_PROGRESS, $openTasks(['documents']).' tarea(s) de documentos abiertas.'],
                    $files === 0 => [self::NOT_APPLICABLE, 'Sin documentos cargados para la empresa.'],
                    default => [self::COMPLETE, 'Documentos recibidos y procesados.'],
                };
            }),
            $this->stage('sales', function () use ($companyId, $from, $to, $data, $openTasks): array {
                $invoices = DB::table('sales_invoices')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to]);
                $total = (clone $invoices)->where('status', '!=', 'void')->count();
                $drafts = (clone $invoices)->where('status', 'draft')->count();
                $unposted = $data === null ? null : collect($data['pending'])->where('origin', 'Ventas')->count();

                return match (true) {
                    $unposted === null => [self::ERROR, 'No fue posible verificar la contabilización de ventas.'],
                    $total === 0 && $openTasks(['sales']) === 0 => [self::NOT_APPLICABLE, 'Sin ventas en el período.'],
                    $unposted > 0 => [self::PENDING, $unposted.' venta(s) sin asiento.'],
                    $drafts > 0 || $openTasks(['sales']) > 0 => [self::IN_PROGRESS, $drafts.' venta(s) en borrador.'],
                    default => [self::COMPLETE, $total.' venta(s) registradas y contabilizadas.'],
                };
            }),
            $this->stage('purchases', function () use ($companyId, $from, $to, $data, $openTasks): array {
                $documents = DB::table('purchase_documents')->where('company_id', $companyId)->whereBetween('document_date', [$from, $to]);
                $total = (clone $documents)->where('status', '!=', 'void')->count();
                $drafts = (clone $documents)->where('status', 'draft')->count();
                $unposted = $data === null ? null : collect($data['pending'])->where('origin', 'Compras')->count();

                return match (true) {
                    $unposted === null => [self::ERROR, 'No fue posible verificar la contabilización de compras.'],
                    $total === 0 && $openTasks(['purchases']) === 0 => [self::NOT_APPLICABLE, 'Sin compras en el período.'],
                    $unposted > 0 => [self::PENDING, $unposted.' compra(s) sin asiento.'],
                    $drafts > 0 || $openTasks(['purchases']) > 0 => [self::IN_PROGRESS, $drafts.' compra(s) en borrador.'],
                    default => [self::COMPLETE, $total.' compra(s) registradas y contabilizadas.'],
                };
            }),
            $this->stage('banks', function () use ($companyId, $from, $to): array {
                $accounts = DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->pluck('id');
                if ($accounts->isEmpty()) {
                    return [self::NOT_APPLICABLE, 'La empresa no tiene cuentas bancarias.'];
                }
                $movements = DB::table('bank_transactions')->whereIn('bank_account_id', $accounts)->whereBetween('transaction_date', [$from, $to]);
                $pending = (clone $movements)->where('reconciliation_status', 'pending')->count();
                $matched = (clone $movements)->where('reconciliation_status', 'matched')->count();

                return match (true) {
                    $pending > 0 && $matched > 0 => [self::IN_PROGRESS, $pending.' movimiento(s) por conciliar.'],
                    $pending > 0 => [self::PENDING, $pending.' movimiento(s) sin conciliar.'],
                    default => [self::COMPLETE, 'Movimientos del período conciliados.'],
                };
            }),
            $this->reconciliationStage('receivables', 'CxC', $data),
            $this->reconciliationStage('payables', 'CxP', $data),
            $this->stage('fiscal', function () use ($companyId, $to, $openTasks): array {
                if (DB::table('company_fiscal_profiles')->where('company_id', $companyId)->value('vat_regime') === 'exempt') {
                    return [self::NOT_APPLICABLE, 'Empresa exenta de IVA.'];
                }
                $end = Carbon::parse($to);
                $book = DB::table('vat_book_periods')->where('company_id', $companyId)->where('year', $end->year)->where('month', $end->month)->value('status');

                return match (true) {
                    $book === 'closed' => [self::COMPLETE, 'Libro IVA del mes cerrado.'],
                    $openTasks(['tax']) > 0 => [self::IN_PROGRESS, $openTasks(['tax']).' tarea(s) fiscales abiertas.'],
                    default => [self::PENDING, 'Libro IVA del mes abierto.'],
                };
            }),
            $this->stage('accounting', function () use ($periodId, $data): array {
                if ($data === null) {
                    return [self::ERROR, 'No fue posible verificar la contabilidad.'];
                }
                $entries = DB::table('journal_entries')->where('accounting_period_id', $periodId);
                $posted = (clone $entries)->whereIn('status', ['posted', 'reversed'])->count();
                $open = (clone $entries)->whereIn('status', ['draft', 'pending_review', 'reviewed', 'approved', 'rejected'])->count();

                return match (true) {
                    ! $data['trial_balance']['balanced'] => [self::BLOCKED, 'El balance de comprobación no cuadra.'],
                    (int) $data['summary']['unsupported_entries'] > 0 => [self::PENDING, $data['summary']['unsupported_entries'].' asiento(s) sin soporte.'],
                    $open > 0 => [self::IN_PROGRESS, $open.' asiento(s) sin contabilizar.'],
                    $posted === 0 => [self::PENDING, 'Sin asientos contabilizados en el período.'],
                    default => [self::COMPLETE, $posted.' asiento(s) contabilizados y cuadrados.'],
                };
            }),
        ];

        $applicable = collect($stages)->where('status', '!=', self::NOT_APPLICABLE)->count();
        $complete = collect($stages)->where('status', self::COMPLETE)->count();
        $hasError = collect($stages)->contains('status', self::ERROR);

        return [
            'stages' => $stages,
            'progress' => $period->closed_at !== null ? 100 : ($hasError ? null : ($applicable === 0 ? 0 : (int) round($complete / $applicable * 100))),
            'applicable' => $applicable,
            'complete' => $complete,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return StageStatus
     */
    private function reconciliationStage(string $key, string $label, ?array $data): array
    {
        return $this->stage($key, function () use ($label, $data): array {
            if ($data === null) {
                return [self::ERROR, 'No fue posible verificar la conciliación.'];
            }
            $items = is_array($data['reconciliations'] ?? null) ? $data['reconciliations'] : [];
            $row = null;
            foreach ($items as $item) {
                if (is_array($item) && ($item['label'] ?? null) === $label) {
                    $row = $item;
                }
            }
            if ($row === null) {
                return [self::ERROR, 'No fue posible verificar la conciliación.'];
            }

            return match (true) {
                (int) $row['auxiliary_cents'] === 0 && (int) $row['ledger_cents'] === 0 => [self::NOT_APPLICABLE, 'Sin saldos en el período.'],
                (int) $row['difference_cents'] !== 0 => [self::PENDING, 'Diferencia de Q '.number_format((int) $row['difference_cents'] / 100, 2).' contra el mayor.'],
                default => [self::COMPLETE, 'Auxiliar y mayor coinciden.'],
            };
        });
    }

    /**
     * @param  callable(): array{0: string, 1: string}  $callback
     * @return StageStatus
     */
    private function stage(string $key, callable $callback): array
    {
        try {
            [$status, $detail] = $callback();
        } catch (Throwable $exception) {
            Log::warning('Progress stage failed', ['stage' => $key, 'error' => $exception->getMessage()]);
            [$status, $detail] = [self::ERROR, 'No fue posible verificar esta etapa.'];
        }

        return ['key' => $key, 'label' => self::STAGES[$key], 'status' => $status, 'detail' => $detail];
    }
}
