<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Monthly VAT books of a company (Libro de Ventas y Servicios, Libro de Compras y Servicios), built from the
 * real documents: registered sales, FEL documents issued in Atlantia and approved purchases. The books are
 * reconciled against the VAT accounts of the ledger and closed month by month.
 *
 * @phpstan-type VatIssue array{kind: string, message: string}
 * @phpstan-type VatAmounts array{taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int}
 * @phpstan-type VatRow array{key: string, source: string, source_id: int, public_id: string, date: string, document_type: string, series: string|null, number: string|null, authorization: string|null, tax_id: string|null, name: string, status: string, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, total_cents: int, issues: list<VatIssue>, accounting: string}
 * @phpstan-type VatCheck array{key: string, label: string, ok: bool, detail: string, blocking?: bool}
 */
class VatBookService
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private FiscalConfigurationService $fiscal, private AccountingService $accounting) {}

    /** Rejects changes to documents dated in a month whose VAT books are closed. */
    public function assertOpen(int $companyId, string $date): void
    {
        $day = CarbonImmutable::parse($date);
        $closed = DB::table('vat_book_periods')->where('company_id', $companyId)
            ->where('year', $day->year)->where('month', $day->month)->where('status', 'closed')->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                'vat_book' => 'El Libro IVA de '.$this->label($day->year, $day->month).' está cerrado. Reábrelo formalmente para modificar documentos de ese mes.',
            ]);
        }
    }

    /**
     * Full book for one month: rows, totals, VAT settlement, ledger reconciliation and closing checks.
     *
     * @return array{year: int, month: int, label: string, from: string, to: string, status: string, period: stdClass, sales: list<VatRow>, purchases: list<VatRow>, totals: array<string, array<string, int>>, settlement: array{sales_vat_cents: int, purchase_vat_cents: int, previous_credit_cents: int, payable_cents: int, carry_forward_cents: int}, ledger: array<string, mixed>, checks: list<VatCheck>, can_close: bool}
     */
    public function book(int $companyId, int $year, int $month): array
    {
        $this->fiscal->ensureDefaults($companyId);
        $this->fiscal->linkDefaultAccounts($companyId);
        [$from, $to] = $this->range($year, $month);

        $sales = $this->salesRows($companyId, $from, $to);
        $purchases = $this->purchaseRows($companyId, $from, $to);
        $salesTotals = $this->totals($sales);
        $purchaseTotals = $this->totals($purchases);
        $period = $this->period($companyId, $year, $month);
        $previous = $this->previousPeriod($companyId, $year, $month);
        $previousCredit = $previous?->status === 'closed' ? (int) $previous->carry_forward_cents : 0;
        $net = $salesTotals['vat_cents'] - $purchaseTotals['vat_cents'] - $previousCredit;
        $ledger = $this->ledger($companyId, $from, $to);
        $salesDifference = $salesTotals['vat_cents'] - $ledger['sales_vat_cents'];
        $purchaseDifference = $purchaseTotals['vat_cents'] - $ledger['purchase_vat_cents'];

        $checks = [
            ['key' => 'documents', 'label' => 'Documentos con datos fiscales completos', 'ok' => $this->issueCount($sales, 'data') + $this->issueCount($purchases, 'data') === 0,
                'detail' => ($this->issueCount($sales, 'data') + $this->issueCount($purchases, 'data')).' documento(s) con datos incompletos'],
            ['key' => 'accounting', 'label' => 'Documentos contabilizados', 'ok' => $this->issueCount($sales, 'accounting') + $this->issueCount($purchases, 'accounting') === 0,
                'detail' => ($this->issueCount($sales, 'accounting') + $this->issueCount($purchases, 'accounting')).' documento(s) con asiento pendiente'],
            ['key' => 'ledger', 'label' => 'IVA cuadrado con el Libro Mayor', 'ok' => $ledger['configured'] && $salesDifference === 0 && $purchaseDifference === 0,
                'detail' => $ledger['configured'] ? 'Diferencia débito '.$this->money($salesDifference).' · crédito '.$this->money($purchaseDifference) : 'Asigna las cuentas de IVA en Parámetros fiscales'],
            ['key' => 'previous', 'label' => 'Mes anterior cerrado', 'ok' => $previous === null || $previous->status === 'closed',
                'detail' => $previous === null ? 'Primer mes con libros' : ($previous->status === 'closed' ? 'Remanente trasladado' : 'El remanente del mes anterior aún no está confirmado'),
                'blocking' => false],
        ];

        return [
            'year' => $year,
            'month' => $month,
            'label' => $this->label($year, $month),
            'from' => $from,
            'to' => $to,
            'status' => (string) $period->status,
            'period' => $period,
            'sales' => $sales,
            'purchases' => $purchases,
            'totals' => ['sales' => $salesTotals, 'purchases' => $purchaseTotals],
            'settlement' => [
                'sales_vat_cents' => $salesTotals['vat_cents'],
                'purchase_vat_cents' => $purchaseTotals['vat_cents'],
                'previous_credit_cents' => $previousCredit,
                'payable_cents' => max(0, $net),
                'carry_forward_cents' => max(0, -$net),
            ],
            'ledger' => [
                ...$ledger,
                'sales_difference_cents' => $salesDifference,
                'purchase_difference_cents' => $purchaseDifference,
            ],
            'checks' => $checks,
            'can_close' => $period->status === 'open' && collect($checks)->every(fn (array $check): bool => $check['ok'] || ($check['blocking'] ?? true) === false),
        ];
    }

    /** @return list<array{year: int, month: int, label: string, status: string}> */
    public function periods(int $companyId): array
    {
        $today = CarbonImmutable::today();
        $months = collect(range(0, 17))->map(fn (int $back): CarbonImmutable => $today->startOfMonth()->subMonths($back));
        $withDocuments = collect(DB::select(<<<'SQL'
            SELECT DISTINCT EXTRACT(YEAR FROM day)::int AS year, EXTRACT(MONTH FROM day)::int AS month FROM (
                SELECT issued_on AS day FROM sales_invoices WHERE company_id = ? AND number IS NOT NULL
                UNION SELECT document_date FROM purchase_documents WHERE company_id = ? AND status = 'approved'
                UNION SELECT issued_on FROM fel_documents WHERE company_id = ? AND status IN ('certified', 'cancelled')
            ) documents
            SQL, [$companyId, $companyId, $companyId]))
            ->map(fn (stdClass $row): CarbonImmutable => CarbonImmutable::create($row->year, $row->month, 1));
        $statuses = DB::table('vat_book_periods')->where('company_id', $companyId)->get()
            ->keyBy(fn (stdClass $row): string => $row->year.'-'.$row->month);

        return array_values($months->merge($withDocuments)
            ->unique(fn (CarbonImmutable $day): string => $day->format('Y-m'))
            ->sortByDesc(fn (CarbonImmutable $day): string => $day->format('Y-m'))
            ->map(fn (CarbonImmutable $day): array => [
                'year' => $day->year,
                'month' => $day->month,
                'label' => $this->label($day->year, $day->month),
                'status' => (string) ($statuses->get($day->year.'-'.$day->month)->status ?? 'open'),
            ])
            ->all());
    }

    public function close(Organization $organization, User $user, int $companyId, int $year, int $month): stdClass
    {
        return DB::transaction(function () use ($organization, $user, $companyId, $year, $month): stdClass {
            $this->accounting->lock($companyId);
            $book = $this->book($companyId, $year, $month);
            if ($book['status'] === 'closed') {
                throw ValidationException::withMessages(['vat_book' => 'Este mes ya está cerrado.']);
            }
            if (! $book['can_close']) {
                $pending = collect($book['checks'])->reject(fn (array $check): bool => $check['ok'] || ($check['blocking'] ?? true) === false)
                    ->map(fn (array $check): string => $check['label'].': '.$check['detail'])->implode('; ');
                throw ValidationException::withMessages(['vat_book' => 'No se puede cerrar el mes. '.$pending.'.']);
            }

            $values = [
                'status' => 'closed',
                'sales_vat_cents' => $book['settlement']['sales_vat_cents'],
                'purchase_vat_cents' => $book['settlement']['purchase_vat_cents'],
                'previous_credit_cents' => $book['settlement']['previous_credit_cents'],
                'payable_cents' => $book['settlement']['payable_cents'],
                'carry_forward_cents' => $book['settlement']['carry_forward_cents'],
                'snapshot' => json_encode([
                    'totals' => $book['totals'],
                    'ledger' => $book['ledger'],
                    'documents' => ['sales' => count($book['sales']), 'purchases' => count($book['purchases'])],
                ], JSON_THROW_ON_ERROR),
                'closed_by' => $user->id,
                'closed_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('vat_book_periods')->updateOrInsert(['company_id' => $companyId, 'year' => $year, 'month' => $month], [...$values, 'created_at' => now()]);
            $period = $this->period($companyId, $year, $month);
            $this->accounting->audit($organization, $user, 'vat_book.closed', 'vat_book_periods', (int) $period->id, [
                'period' => sprintf('%04d-%02d', $year, $month),
                'payable_cents' => $values['payable_cents'],
                'carry_forward_cents' => $values['carry_forward_cents'],
            ]);

            return $period;
        });
    }

    public function reopen(Organization $organization, User $user, int $companyId, int $year, int $month, string $reason): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $year, $month, $reason): void {
            $this->accounting->lock($companyId);
            $period = $this->period($companyId, $year, $month);
            if ($period->status !== 'closed') {
                throw ValidationException::withMessages(['vat_book' => 'Solo se puede reabrir un mes cerrado.']);
            }
            [$nextYear, $nextMonth] = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
            if ($this->period($companyId, $nextYear, $nextMonth)->status === 'closed') {
                throw ValidationException::withMessages(['vat_book' => 'Reabre primero '.$this->label($nextYear, $nextMonth).': su remanente depende de este mes.']);
            }

            DB::table('vat_book_periods')->where('id', $period->id)->update([
                'status' => 'open',
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $user, 'vat_book.reopened', 'vat_book_periods', (int) $period->id, [
                'period' => sprintf('%04d-%02d', $year, $month),
                'reason' => $reason,
            ]);
        });
    }

    public function label(int $year, int $month): string
    {
        return ucfirst(self::MONTHS[$month - 1]).' '.$year;
    }

    /** @return list<VatRow> */
    private function salesRows(int $companyId, string $from, string $to): array
    {
        $invoices = DB::table('sales_invoices')->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'void'])->whereNotNull('number')
            ->whereBetween('issued_on', [$from, $to])->orderBy('issued_on')->orderBy('number')->get()
            ->map(fn (stdClass $row): array => $this->row('sales_invoice', (int) $row->id, (string) $row->public_id, (string) $row->issued_on,
                $row->fiscal_document_type, $row->fiscal_series, $row->fiscal_number, $row->fiscal_authorization,
                $row->customer_tax_id, (string) $row->customer_name, $row->status === 'void', (int) $row->total_cents, [
                    'taxable_goods_cents' => (int) $row->taxable_goods_cents, 'taxable_services_cents' => (int) $row->taxable_services_cents,
                    'exempt_cents' => (int) $row->exempt_cents, 'small_taxpayer_cents' => (int) $row->small_taxpayer_cents, 'vat_cents' => (int) $row->vat_cents,
                ], 'sales'))
            ->all();

        $felLines = DB::table('fel_document_lines')
            ->join('fel_documents', 'fel_documents.id', '=', 'fel_document_lines.fel_document_id')
            ->where('fel_documents.company_id', $companyId)->whereBetween('fel_documents.issued_on', [$from, $to])
            ->groupBy('fel_document_lines.fel_document_id')
            ->select('fel_document_lines.fel_document_id')
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN fel_document_lines.classification = 'goods' THEN fel_document_lines.total_cents - fel_document_lines.tax_cents ELSE 0 END), 0) AS BIGINT) AS goods_cents")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN fel_document_lines.classification <> 'goods' THEN fel_document_lines.total_cents - fel_document_lines.tax_cents ELSE 0 END), 0) AS BIGINT) AS services_cents")
            ->get()->keyBy('fel_document_id');

        $fel = DB::table('fel_documents')->where('company_id', $companyId)
            ->whereIn('status', ['certified', 'cancelled'])->whereBetween('issued_on', [$from, $to])
            ->orderBy('issued_on')->orderBy('number')->get()
            ->map(function (stdClass $row) use ($felLines): array {
                $receiver = json_decode((string) $row->receiver_snapshot, true) ?: [];
                $lines = $felLines->get($row->id);

                return $this->row('fel_document', (int) $row->id, (string) $row->public_id, (string) $row->issued_on,
                    (string) $row->document_type, $row->fel_series ?? $row->series, $row->fel_number ?? (string) $row->number,
                    $row->authorization_number, $receiver['nit'] ?? null, (string) ($receiver['name'] ?? 'Consumidor final'),
                    $row->status === 'cancelled', (int) $row->total_cents, [
                        'taxable_goods_cents' => (int) ($lines->goods_cents ?? 0), 'taxable_services_cents' => (int) ($lines->services_cents ?? 0),
                        'exempt_cents' => 0, 'small_taxpayer_cents' => 0, 'vat_cents' => (int) $row->tax_cents,
                    ], 'sales');
            })
            ->all();

        $rows = [...array_values($invoices), ...array_values($fel)];
        usort($rows, fn (array $a, array $b): int => [$a['date'], (string) $a['number']] <=> [$b['date'], (string) $b['number']]);

        return $this->withAccounting($companyId, $rows);
    }

    /** @return list<VatRow> */
    private function purchaseRows(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('purchase_documents')->where('company_id', $companyId)->where('status', 'approved')
            ->whereBetween('document_date', [$from, $to])->orderBy('document_date')->orderBy('number')->get()
            ->map(fn (stdClass $row): array => $this->row('purchase_document', (int) $row->id, (string) $row->public_id, (string) $row->document_date,
                $row->fiscal_document_type, $row->fiscal_series, $row->fiscal_number ?? $row->external_reference, $row->fiscal_authorization,
                $row->supplier_tax_id, (string) $row->supplier_name, false, (int) $row->total_cents, [
                    'taxable_goods_cents' => (int) $row->taxable_goods_cents, 'taxable_services_cents' => (int) $row->taxable_services_cents,
                    'exempt_cents' => (int) $row->exempt_cents, 'small_taxpayer_cents' => (int) $row->small_taxpayer_cents, 'vat_cents' => (int) $row->vat_cents,
                ], 'purchases'))
            ->all();

        return $this->withAccounting($companyId, array_values($rows));
    }

    /**
     * @param  VatAmounts  $amounts
     * @return VatRow
     */
    private function row(string $source, int $id, string $publicId, string $date, ?string $type, ?string $series, ?string $number,
        ?string $authorization, ?string $taxId, string $name, bool $annulled, int $total, array $amounts, string $book): array
    {
        $breakdown = array_sum($amounts);
        $issues = [];
        if ($number === null || $number === '') {
            $issues[] = ['kind' => 'data', 'message' => 'Falta el número del documento fiscal'];
        }
        $taxId = $taxId === null ? null : strtoupper(trim($taxId));
        if ($taxId === null || $taxId === '' || ($book === 'purchases' && $taxId === 'CF')) {
            $issues[] = ['kind' => 'data', 'message' => $book === 'purchases' ? 'Falta el NIT del proveedor' : 'Falta el NIT del cliente (usa CF para consumidor final)'];
        }
        if (! $annulled && $breakdown === 0) {
            $issues[] = ['kind' => 'data', 'message' => 'Sin desglose fiscal (bienes, servicios, exento e IVA)'];
        }

        // Annulled sales stay in the book with zero amounts, as SAT requires.
        if ($annulled) {
            $amounts = ['taxable_goods_cents' => 0, 'taxable_services_cents' => 0, 'exempt_cents' => 0, 'small_taxpayer_cents' => 0, 'vat_cents' => 0];
        }

        return [
            'key' => $source.':'.$id,
            'source' => $source,
            'source_id' => $id,
            'public_id' => $publicId,
            'date' => $date,
            'document_type' => $type ?? 'FACT',
            'series' => $series,
            'number' => $number,
            'authorization' => $authorization,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => $annulled ? 'annulled' : 'valid',
            'taxable_goods_cents' => $amounts['taxable_goods_cents'],
            'taxable_services_cents' => $amounts['taxable_services_cents'],
            'exempt_cents' => $amounts['exempt_cents'],
            'small_taxpayer_cents' => $amounts['small_taxpayer_cents'],
            'vat_cents' => $amounts['vat_cents'],
            'total_cents' => $annulled ? 0 : ($breakdown === 0 ? $total : $breakdown),
            'issues' => $issues,
            'accounting' => $annulled ? 'not_applicable' : 'missing',
        ];
    }

    /**
     * Marks each valid document with the state of its journal entry.
     *
     * @param  list<VatRow>  $rows
     * @return list<VatRow>
     */
    private function withAccounting(int $companyId, array $rows): array
    {
        $entries = DB::table('accounting_source_entries')
            ->join('journal_entries', 'journal_entries.id', '=', 'accounting_source_entries.journal_entry_id')
            ->where('accounting_source_entries.company_id', $companyId)
            ->where('accounting_source_entries.event', 'origin')
            ->whereIn('accounting_source_entries.source_type', ['sales_invoice', 'purchase_document', 'fel_document'])
            ->whereIn('accounting_source_entries.source_id', array_column($rows, 'source_id') ?: [0])
            ->get(['accounting_source_entries.source_type', 'accounting_source_entries.source_id', 'journal_entries.status'])
            ->keyBy(fn (stdClass $row): string => $row->source_type.':'.$row->source_id);

        foreach ($rows as $index => $row) {
            if ($row['status'] === 'annulled') {
                continue;
            }
            $status = $entries->get($row['key'])->status ?? null;
            $accounting = match (true) {
                in_array($status, ['posted', 'reversed'], true) => 'posted',
                $status !== null && $status !== 'void' => 'pending',
                default => 'missing',
            };
            $rows[$index]['accounting'] = $accounting;
            if ($accounting === 'pending') {
                $rows[$index]['issues'][] = ['kind' => 'accounting', 'message' => 'Asiento pendiente de contabilizar'];
            } elseif ($accounting === 'missing') {
                $rows[$index]['issues'][] = ['kind' => 'accounting', 'message' => 'Sin asiento contable'];
            }
        }

        return $rows;
    }

    /**
     * @param  list<VatRow>  $rows
     * @return array{documents: int, annulled: int, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, total_cents: int}
     */
    private function totals(array $rows): array
    {
        $totals = ['documents' => count($rows), 'annulled' => 0, 'taxable_goods_cents' => 0, 'taxable_services_cents' => 0,
            'exempt_cents' => 0, 'small_taxpayer_cents' => 0, 'vat_cents' => 0, 'total_cents' => 0];
        foreach ($rows as $row) {
            $totals['annulled'] += $row['status'] === 'annulled' ? 1 : 0;
            $totals['taxable_goods_cents'] += $row['taxable_goods_cents'];
            $totals['taxable_services_cents'] += $row['taxable_services_cents'];
            $totals['exempt_cents'] += $row['exempt_cents'];
            $totals['small_taxpayer_cents'] += $row['small_taxpayer_cents'];
            $totals['vat_cents'] += $row['vat_cents'];
            $totals['total_cents'] += $row['total_cents'];
        }

        return $totals;
    }

    /**
     * VAT booked in the ledger for the month on the accounts configured on the general VAT tax.
     *
     * @return array{configured: bool, sales_vat_cents: int, purchase_vat_cents: int, payable_account: string|null, recoverable_account: string|null}
     */
    private function ledger(int $companyId, string $from, string $to): array
    {
        $tax = DB::table('fiscal_taxes')->where('company_id', $companyId)->where('code', 'IVA_GENERAL')->first();
        $payable = $tax?->payable_account_id;
        $recoverable = $tax?->recoverable_account_id;
        $movement = fn (?int $accountId): stdClass => DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $accountId ?? 0)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit_cents), 0) AS BIGINT) AS debit_cents, CAST(COALESCE(SUM(journal_lines.credit_cents), 0) AS BIGINT) AS credit_cents')
            ->first();
        $sales = $movement($payable === null ? null : (int) $payable);
        $purchases = $movement($recoverable === null ? null : (int) $recoverable);
        $label = fn (?int $id): ?string => $id === null ? null
            : DB::table('ledger_accounts')->where('id', $id)->selectRaw("code || ' · ' || name AS label")->value('label');

        return [
            'configured' => $payable !== null && $recoverable !== null,
            'sales_vat_cents' => (int) $sales->credit_cents - (int) $sales->debit_cents,
            'purchase_vat_cents' => (int) $purchases->debit_cents - (int) $purchases->credit_cents,
            'payable_account' => $label($payable === null ? null : (int) $payable),
            'recoverable_account' => $label($recoverable === null ? null : (int) $recoverable),
        ];
    }

    /** @param list<VatRow> $rows */
    private function issueCount(array $rows, string $kind): int
    {
        return count(array_filter($rows, fn (array $row): bool => in_array($kind, array_column($row['issues'], 'kind'), true)));
    }

    private function period(int $companyId, int $year, int $month): stdClass
    {
        return DB::table('vat_book_periods')->where('company_id', $companyId)->where('year', $year)->where('month', $month)->first()
            ?? (object) ['id' => null, 'status' => 'open', 'closed_at' => null, 'closed_by' => null, 'reopened_at' => null, 'reopen_reason' => null,
                'payable_cents' => 0, 'carry_forward_cents' => 0];
    }

    private function previousPeriod(int $companyId, int $year, int $month): ?stdClass
    {
        [$previousYear, $previousMonth] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
        [$from, $to] = $this->range($previousYear, $previousMonth);
        $period = DB::table('vat_book_periods')->where('company_id', $companyId)->where('year', $previousYear)->where('month', $previousMonth)->first();
        if ($period !== null) {
            return $period;
        }

        $hadDocuments = DB::table('sales_invoices')->where('company_id', $companyId)->whereNotNull('number')->whereBetween('issued_on', [$from, $to])->exists()
            || DB::table('purchase_documents')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('document_date', [$from, $to])->exists()
            || DB::table('fel_documents')->where('company_id', $companyId)->whereIn('status', ['certified', 'cancelled'])->whereBetween('issued_on', [$from, $to])->exists();

        return $hadDocuments ? (object) ['status' => 'open', 'carry_forward_cents' => 0] : null;
    }

    /** @return array{0: string, 1: string} */
    private function range(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function money(int $cents): string
    {
        return 'Q'.number_format($cents / 100, 2);
    }
}
