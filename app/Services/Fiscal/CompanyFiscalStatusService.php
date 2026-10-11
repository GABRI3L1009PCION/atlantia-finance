<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single source of the fiscal state of a company for a reference month: stages (obligations, VAT book,
 * withholdings, FEL, documents, fiscal-accounting reconciliation and review) with COMPLETE, IN_PROGRESS, PENDING,
 * BLOCKED, ERROR or NOT_APPLICABLE; progress over applicable stages only; overall status and flags.
 *
 * @phpstan-type FiscalStage array{key: string, label: string, status: string, detail: string}
 */
class CompanyFiscalStatusService
{
    public const STAGES = ['obligations' => 'Obligaciones', 'vat_book' => 'Libros IVA', 'withholdings' => 'Retenciones', 'fel' => 'FEL', 'documents' => 'Doc. fiscales', 'reconciliation' => 'Conciliación', 'review' => 'Revisión'];

    public const STATUS_LABELS = [
        'not_started' => 'Sin iniciar',
        'in_progress' => 'En proceso',
        'with_pending_items' => 'Con pendientes',
        'ready_for_review' => 'En revisión',
        'changes_requested' => 'Cambios solicitados',
        'approved' => 'Aprobada',
        'completed' => 'Completa',
    ];

    public function __construct(
        private VatBookService $vatBooks,
        private FiscalObligationReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function forCompany(int $companyId, int $year, int $month): array
    {
        $company = DB::table('companies')->leftJoin('company_fiscal_profiles as profile', 'profile.company_id', '=', 'companies.id')->where('companies.id', $companyId)
            ->first(['companies.id', 'companies.public_id', 'profile.vat_regime']);
        abort_if($company === null, 404);
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $obligations = DB::table('fiscal_obligations')->where('company_id', $companyId)->where('reference_year', $year)->where('reference_month', $month)->whereNotIn('status', ['cancelled', 'not_applicable'])->get();
        $overdue = DB::table('fiscal_obligations')->where('company_id', $companyId)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable'])->where('due_on', '<', now()->toDateString())->count();
        $book = null;
        $bookError = false;
        if (($company->vat_regime ?? 'general') !== 'exempt') {
            try {
                $book = $this->vatBooks->book($companyId, $year, $month);
            } catch (Throwable $exception) {
                Log::warning('Fiscal status VAT book failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);
                $bookError = true;
            }
        }
        $done = $obligations->whereIn('status', ['completed'])->count();
        $stale = $obligations->filter(fn (object $obligation): bool => $this->readiness->isStale($obligation))->count();

        $stages = [
            $this->stage('obligations', fn (): array => match (true) {
                $obligations->isEmpty() => ['NOT_APPLICABLE', 'Sin obligaciones generadas para el mes.'],
                $done === $obligations->count() => ['COMPLETE', $done.' de '.$obligations->count().' presentadas.'],
                $overdue > 0 => ['BLOCKED', $overdue.' obligación(es) vencidas.'],
                $obligations->whereIn('status', ['in_progress', 'ready_for_review', 'approved', 'changes_requested'])->isNotEmpty() => ['IN_PROGRESS', $done.' de '.$obligations->count().' presentadas.'],
                default => ['PENDING', $done.' de '.$obligations->count().' presentadas.'],
            }),
            $this->stage('vat_book', fn (): array => match (true) {
                $bookError => ['ERROR', 'No fue posible calcular el Libro IVA.'],
                $book === null => ['NOT_APPLICABLE', 'Empresa exenta de IVA.'],
                $book['status'] === 'closed' => ['COMPLETE', 'Libro del mes cerrado.'],
                $book['sales'] === [] && $book['purchases'] === [] => ['PENDING', 'Libro sin documentos en el mes.'],
                $book['can_close'] => ['IN_PROGRESS', 'Listo para cerrar.'],
                default => ['PENDING', collect($book['checks'])->where('ok', false)->pluck('detail')->implode(' · ')],
            }),
            $this->stage('withholdings', function () use ($obligations): array {
                $withholding = $obligations->firstWhere('obligation_type', 'withholding');

                return match (true) {
                    $withholding === null => ['NOT_APPLICABLE', 'La empresa no tiene retenciones configuradas.'],
                    $withholding->status === 'completed' => ['COMPLETE', 'Retenciones presentadas.'],
                    $withholding->status === 'pending' => ['PENDING', 'Retenciones sin preparar.'],
                    default => ['IN_PROGRESS', 'Retenciones en preparación.'],
                };
            }),
            $this->stage('fel', function () use ($companyId, $from, $to): array {
                $documents = DB::table('fel_documents')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to]);
                $failed = (clone $documents)->where('status', 'failed')->count();
                $pending = (clone $documents)->whereIn('status', ['pending', 'processing'])->count();

                return match (true) {
                    (clone $documents)->count() === 0 => ['NOT_APPLICABLE', 'Sin FEL en el mes.'],
                    $failed > 0 => ['BLOCKED', $failed.' FEL con error.'],
                    $pending > 0 => ['IN_PROGRESS', $pending.' FEL en certificación.'],
                    default => ['COMPLETE', 'FEL certificados.'],
                };
            }),
            $this->stage('documents', function () use ($companyId): array {
                $requests = DB::table('operational_incidents')->where('company_id', $companyId)->where('type', 'document')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();

                return $requests > 0 ? ['PENDING', $requests.' documento(s) faltantes.'] : ['COMPLETE', 'Sin documentos faltantes.'];
            }),
            $this->stage('reconciliation', fn (): array => match (true) {
                $bookError => ['ERROR', 'No fue posible calcular la conciliación.'],
                $book === null => ['NOT_APPLICABLE', 'Empresa exenta de IVA.'],
                ! $book['ledger']['configured'] => ['BLOCKED', 'Cuentas de IVA sin asignar en Parámetros fiscales.'],
                (int) $book['ledger']['sales_difference_cents'] === 0 && (int) $book['ledger']['purchase_difference_cents'] === 0 => ['COMPLETE', 'IVA cuadrado con el mayor.'],
                default => ['PENDING', 'Diferencia débito Q '.number_format((int) $book['ledger']['sales_difference_cents'] / 100, 2).' · crédito Q '.number_format((int) $book['ledger']['purchase_difference_cents'] / 100, 2)],
            }),
            $this->stage('review', fn (): array => match (true) {
                $obligations->isEmpty() => ['NOT_APPLICABLE', 'Nada que revisar.'],
                $stale > 0 => ['BLOCKED', $stale.' obligación(es) con datos cambiados; recalcular.'],
                $obligations->where('status', 'changes_requested')->isNotEmpty() => ['PENDING', 'Cambios solicitados por el revisor.'],
                $obligations->whereIn('status', ['approved', 'completed'])->count() === $obligations->count() => ['COMPLETE', 'Todo aprobado.'],
                $obligations->where('status', 'ready_for_review')->isNotEmpty() => ['IN_PROGRESS', $obligations->where('status', 'ready_for_review')->count().' en revisión.'],
                default => ['PENDING', 'Aún no se envía a revisión.'],
            }),
        ];

        $applicable = collect($stages)->where('status', '!=', 'NOT_APPLICABLE');
        $hasError = collect($stages)->contains('status', 'ERROR');
        $status = match (true) {
            $obligations->isNotEmpty() && $done === $obligations->count() => 'completed',
            $obligations->where('status', 'changes_requested')->isNotEmpty() => 'changes_requested',
            $obligations->isNotEmpty() && $obligations->whereIn('status', ['approved', 'completed'])->count() === $obligations->count() => 'approved',
            $obligations->where('status', 'ready_for_review')->isNotEmpty() && $obligations->whereIn('status', ['pending', 'in_progress'])->isEmpty() => 'ready_for_review',
            collect($stages)->whereIn('status', ['BLOCKED', 'PENDING'])->isNotEmpty() && $obligations->where('status', '!=', 'pending')->isNotEmpty() => 'with_pending_items',
            $obligations->where('status', '!=', 'pending')->isNotEmpty() => 'in_progress',
            default => 'not_started',
        };
        $next = $obligations->whereNotIn('status', ['completed'])->sortBy('due_on')->first();

        return [
            'stages' => $stages,
            'progress' => $hasError ? null : ($applicable->isEmpty() ? 0 : (int) round($applicable->where('status', 'COMPLETE')->count() / $applicable->count() * 100)),
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'obligations_total' => $obligations->count(),
            'obligations_completed' => $done,
            'overdue' => $overdue > 0,
            'blocked' => collect($stages)->contains('status', 'BLOCKED'),
            'critical' => collect($stages)->firstWhere('key', 'fel')['status'] === 'BLOCKED',
            'due_on' => $next?->due_on,
            'vat_book_status' => $book === null ? ($bookError ? 'error' : 'not_applicable') : (string) $book['status'],
        ];
    }

    /**
     * @param  callable(): array{0: string, 1: string}  $callback
     * @return FiscalStage
     */
    private function stage(string $key, callable $callback): array
    {
        try {
            [$status, $detail] = $callback();
        } catch (Throwable $exception) {
            Log::warning('Fiscal stage failed', ['stage' => $key, 'error' => $exception->getMessage()]);
            [$status, $detail] = ['ERROR', 'No fue posible verificar.'];
        }

        return ['key' => $key, 'label' => self::STAGES[$key], 'status' => $status, 'detail' => $detail];
    }
}
