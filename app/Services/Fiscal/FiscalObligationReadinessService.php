<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Accounting\AccountingDashboardService;
use App\Services\Operations\ReviewObservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Checklist of a fiscal obligation computed from the source modules (VAT books, sales, purchases, FEL, accounting,
 * documents and observations) and the fingerprint of those sources, used to detect work prepared or approved on data
 * that changed afterwards.
 *
 * @phpstan-type FiscalCheck array{key: string, label: string, status: string, detail: string, destination: string|null}
 */
class FiscalObligationReadinessService
{
    public const LABELS = ['PASSED' => 'Correcto', 'WARNING' => 'Advertencia', 'BLOCKED' => 'Bloqueante', 'NOT_APPLICABLE' => 'No aplica', 'ERROR' => 'No disponible'];

    public function __construct(
        private VatBookService $vatBooks,
        private AccountingDashboardService $dashboard,
    ) {}

    /** @return array{checks: list<FiscalCheck>, ready: bool} */
    public function check(stdClass $obligation): array
    {
        $company = DB::table('companies')->where('id', $obligation->company_id)->first(['public_id']);
        $base = '/accounting/companies/'.$company?->public_id;
        [$from, $to] = $this->range($obligation);
        $checks = [];

        if (in_array($obligation->obligation_type, ['iva'], true)) {
            try {
                $book = $this->vatBooks->book((int) $obligation->company_id, (int) $obligation->reference_year, (int) $obligation->reference_month);
                foreach ($book['checks'] as $check) {
                    if ($check['key'] === 'previous') {
                        $checks[] = ['key' => 'vat_previous', 'label' => $check['label'], 'status' => $check['ok'] ? 'PASSED' : 'WARNING', 'detail' => $check['detail'], 'destination' => $base.'/vat-books'];

                        continue;
                    }
                    $checks[] = ['key' => 'vat_'.$check['key'], 'label' => $check['label'], 'status' => $check['ok'] ? 'PASSED' : 'BLOCKED', 'detail' => $check['detail'], 'destination' => $base.'/vat-books'];
                }
                $checks[] = ['key' => 'vat_book_status', 'label' => 'Libro IVA del mes', 'status' => $book['status'] === 'closed' ? 'PASSED' : 'WARNING', 'detail' => $book['status'] === 'closed' ? 'Libro cerrado.' : 'El libro sigue abierto; se cierra en Libros IVA una vez aprobada la declaración.', 'destination' => $base.'/vat-books'];
            } catch (Throwable $exception) {
                Log::warning('Fiscal readiness VAT book failed', ['obligation' => $obligation->id, 'error' => $exception->getMessage()]);
                $checks[] = ['key' => 'vat_book', 'label' => 'Libro IVA', 'status' => 'ERROR', 'detail' => 'No fue posible calcular el Libro IVA.', 'destination' => $base.'/vat-books'];
            }
        }

        if ($obligation->obligation_type === 'isr') {
            $checks[] = $this->safe('accounting', 'Contabilidad del período', function () use ($obligation, $from, $to, $base): array {
                $open = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('accounting_periods.company_id', $obligation->company_id)
                    ->whereBetween('journal_entries.entry_date', [$from, $to])->whereIn('journal_entries.status', ['draft', 'pending_review', 'reviewed', 'approved'])->count();
                $period = DB::table('accounting_periods')->where('company_id', $obligation->company_id)->where('starts_on', '<=', $to)->where('ends_on', '>=', $to)->value('id');
                $balanced = $period === null ? true : $this->dashboard->forCompany((int) $obligation->company_id, (int) $period)['trial_balance']['balanced'];

                return match (true) {
                    ! $balanced => ['BLOCKED', 'El balance de comprobación no cuadra.', $base.'#balance-comprobacion'],
                    $open > 0 => ['BLOCKED', $open.' asiento(s) sin contabilizar en el período.', $base.'#libro-diario'],
                    default => ['PASSED', 'Resultados contabilizados y cuadrados.', $base.'#balance-comprobacion'],
                };
            });
        }

        if ($obligation->obligation_type === 'withholding') {
            $checks[] = $this->safe('withholdings', 'Retenciones registradas', function () use ($obligation, $from, $to, $base): array {
                $records = DB::table('operational_records')->where('company_id', $obligation->company_id)->where('module', 'impuestos')->whereBetween('effective_on', [$from, $to])
                    ->where(fn ($query) => $query->where('record_type', 'ilike', '%reten%')->orWhere('title', 'ilike', '%reten%'))->count();

                return $records > 0 ? ['PASSED', $records.' registro(s) de retenciones del mes.', $base.'/operations/impuestos'] : ['WARNING', 'No hay retenciones registradas en el mes; confirma que no hubo.', $base.'/operations/impuestos'];
            });
        }

        $checks[] = $this->safe('fel', 'Documentos FEL del mes', function () use ($obligation, $from, $to, $base): array {
            $total = DB::table('fel_documents')->where('company_id', $obligation->company_id)->whereBetween('issued_on', [$from, $to])->count();
            $failed = DB::table('fel_documents')->where('company_id', $obligation->company_id)->whereBetween('issued_on', [$from, $to])->where('status', 'failed')->count();
            $pending = DB::table('fel_documents')->where('company_id', $obligation->company_id)->whereBetween('issued_on', [$from, $to])->whereIn('status', ['pending', 'processing'])->count();

            return match (true) {
                $total === 0 => ['NOT_APPLICABLE', 'Sin documentos FEL en el período.', null],
                $failed > 0 => ['BLOCKED', $failed.' documento(s) FEL con error.', $base.'/fel'],
                $pending > 0 => ['WARNING', $pending.' documento(s) FEL en certificación.', $base.'/fel'],
                default => ['PASSED', $total.' documento(s) FEL certificados o anulados.', $base.'/fel'],
            };
        });

        $checks[] = $this->safe('documents', 'Documentos fiscales', function () use ($obligation): array {
            $requests = DB::table('operational_incidents')->where('company_id', $obligation->company_id)->where('type', 'document')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();

            return $requests === 0 ? ['PASSED', 'Sin documentos solicitados pendientes.', null] : ['WARNING', $requests.' documento(s) solicitados sin recibir.', '/tax/documents'];
        });

        $checks[] = $this->safe('observations', 'Observaciones de la obligación', function () use ($obligation): array {
            $open = DB::table('review_observations')->where('related_type', 'fiscal_obligations')->where('related_id', $obligation->id)->whereIn('status', ReviewObservationService::CORRECTABLE)->count();

            return $open === 0 ? ['PASSED', 'Sin observaciones pendientes.', null] : ['BLOCKED', $open.' observación(es) sin corregir.', '/tax/observations'];
        });

        $blocking = collect($checks)->whereIn('status', ['BLOCKED', 'ERROR'])->count();

        return ['checks' => $checks, 'ready' => $blocking === 0];
    }

    /** Fingerprint of the source data of the obligation (documents, FEL, entries and records of its reference range). */
    public function inputsHash(stdClass $obligation): string
    {
        [$from, $to] = $this->range($obligation);
        $companyId = (int) $obligation->company_id;
        $parts = [
            'sales' => DB::table('sales_invoices')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to])->orderBy('id')->get(['id', 'status', 'total_cents', 'updated_at'])->toArray(),
            'purchases' => DB::table('purchase_documents')->where('company_id', $companyId)->whereBetween('document_date', [$from, $to])->orderBy('id')->get(['id', 'status', 'total_cents', 'updated_at'])->toArray(),
            'fel' => DB::table('fel_documents')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to])->orderBy('id')->get(['id', 'status', 'total_cents', 'updated_at'])->toArray(),
        ];
        if ($obligation->obligation_type === 'isr') {
            $parts['entries'] = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('accounting_periods.company_id', $companyId)
                ->whereBetween('journal_entries.entry_date', [$from, $to])->whereIn('journal_entries.status', ['posted', 'reversed'])->orderBy('journal_entries.id')->get(['journal_entries.id', 'journal_entries.status', 'journal_entries.updated_at'])->toArray();
        }
        if ($obligation->obligation_type === 'withholding') {
            $parts['records'] = DB::table('operational_records')->where('company_id', $companyId)->where('module', 'impuestos')->whereBetween('effective_on', [$from, $to])->orderBy('id')->get(['id', 'status', 'amount_cents', 'updated_at'])->toArray();
        }

        return hash('sha256', (string) json_encode($parts));
    }

    public function isStale(stdClass $obligation): bool
    {
        return in_array($obligation->status, ['ready_for_review', 'approved'], true) && $obligation->inputs_hash !== null && ! hash_equals((string) $obligation->inputs_hash, $this->inputsHash($obligation));
    }

    /** @return array{0: string, 1: string} */
    public function range(stdClass $obligation): array
    {
        $end = Carbon::create((int) $obligation->reference_year, (int) $obligation->reference_month, 1)->endOfMonth();
        $start = $obligation->obligation_type === 'isr' && str_starts_with((string) $obligation->name, 'Pago trimestral') ? $end->copy()->subMonths(2)->startOfMonth() : $end->copy()->startOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * @param  callable(): array{0: string, 1: string, 2: string|null}  $callback
     * @return FiscalCheck
     */
    private function safe(string $key, string $label, callable $callback): array
    {
        try {
            [$status, $detail, $destination] = $callback();
        } catch (Throwable $exception) {
            Log::warning('Fiscal readiness check failed', ['check' => $key, 'error' => $exception->getMessage()]);
            [$status, $detail, $destination] = ['ERROR', 'No fue posible verificar.', null];
        }

        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'destination' => $destination];
    }
}
