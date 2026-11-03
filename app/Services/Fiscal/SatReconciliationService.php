<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Compares a month of the books with what the SAT says the company issued and received:
 *  - DTE in the SAT that are not in the books (an invoice never registered: lost VAT credit or undeclared sales);
 *  - DTE the SAT shows as annulled that are still valid in the books (a credit that cannot be claimed);
 *  - electronic documents of the books that the SAT does not list (mistyped authorization or a document of another NIT);
 *  - documents whose total differs from the SAT.
 * Received documents are compared with every purchase not voided (a draft counts as registered, pending approval);
 * issued ones with the Libro de Ventas.
 *
 * @phpstan-type SatIssue array{kind: string, severity: string, direction: string, authorization: string|null, document: string, counterparty: string, date: string, sat_total_cents: int|null, books_total_cents: int|null, message: string}
 */
class SatReconciliationService
{
    public const KINDS = [
        'missing_in_books' => 'En la SAT pero no en libros',
        'annulled_in_sat' => 'Anulado en la SAT, vigente en libros',
        'not_in_sat' => 'En libros pero no en la SAT',
        'amount_mismatch' => 'Total distinto al de la SAT',
        'pending_approval' => 'Registrado en borrador',
    ];

    public function __construct(private VatBookService $books) {}

    /**
     * @return array{period: string, has_received: bool, has_issued: bool, sat: array{received: int, issued: int, annulled: int}, summary: array<string, int>, issues: list<SatIssue>, registrable: int}
     */
    public function compare(int $companyId, int $year, int $month): array
    {
        $period = sprintf('%04d-%02d', $year, $month);
        $records = DB::table('sat_dte_records')->where('company_id', $companyId)->where('period', $period)->orderBy('issued_on')->orderBy('id')->get();
        $received = $records->where('direction', 'received')->values();
        $issued = $records->where('direction', 'issued')->values();
        $issues = [...$this->purchases($companyId, $period, $received), ...$this->sales($companyId, $year, $month, $issued)];
        $summary = array_fill_keys(array_keys(self::KINDS), 0);
        foreach ($issues as $issue) {
            $summary[$issue['kind']]++;
        }

        return [
            'period' => $period, 'has_received' => $received->isNotEmpty(), 'has_issued' => $issued->isNotEmpty(),
            'sat' => ['received' => $received->count(), 'issued' => $issued->count(), 'annulled' => $records->where('status', 'annulled')->count()],
            'summary' => $summary, 'issues' => $issues,
            'registrable' => $received->filter(fn (stdClass $record): bool => $record->status === 'valid' && in_array($record->document_type, DocumentVatCalculator::PURCHASE_TYPES, true)
                && collect($issues)->contains(fn (array $issue): bool => $issue['kind'] === 'missing_in_books' && $issue['authorization'] === $record->authorization))->count(),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $records
     * @return list<SatIssue>
     */
    private function purchases(int $companyId, string $period, Collection $records): array
    {
        $purchases = DB::table('purchase_documents')->where('company_id', $companyId)
            ->where(fn ($query) => $query->whereRaw("to_char(document_date, 'YYYY-MM') = ?", [$period])
                ->orWhereIn(DB::raw('UPPER(fiscal_authorization)'), $records->pluck('authorization')->map(fn ($value): string => Str::upper((string) $value))->all()))
            ->get(['id', 'public_id', 'status', 'document_date', 'fiscal_document_type', 'fiscal_series', 'fiscal_number', 'fiscal_authorization', 'supplier_name', 'supplier_tax_id', 'total_cents', 'currency', 'foreign_total_cents']);
        $issues = [];
        $matched = [];
        foreach ($records as $record) {
            $purchase = $this->find($purchases, (string) $record->authorization, $record->issuer_tax_id, $record->series, $record->number, 'purchase');
            $document = $this->label((string) $record->document_type, $record->series, $record->number);
            $counterparty = trim(($record->issuer_name ?? '').' · NIT '.($record->issuer_tax_id ?? '—'));
            $base = ['direction' => 'received', 'authorization' => (string) $record->authorization, 'document' => $document, 'counterparty' => $counterparty,
                'date' => (string) $record->issued_on, 'sat_total_cents' => (int) $record->total_cents];
            if ($purchase !== null) {
                $matched[(int) $purchase->id] = true;
            }
            $booksTotal = $purchase === null ? null : $this->purchaseTotal($purchase, (string) $record->currency);
            if ($record->status === 'annulled') {
                if ($purchase !== null && $purchase->status !== 'void') {
                    $issues[] = [...$base, 'kind' => 'annulled_in_sat', 'severity' => 'critical', 'books_total_cents' => $booksTotal,
                        'message' => 'El proveedor anuló este documento: anula la compra para no tomar un crédito fiscal que no existe.'];
                }

                continue;
            }
            if ($purchase === null || $purchase->status === 'void') {
                if (in_array($record->document_type, ['FACT', 'FCAM', 'FPEQ', 'FCAP', 'FESP', 'NDEB', 'NCRE'], true)) {
                    $issues[] = [...$base, 'kind' => 'missing_in_books', 'severity' => 'high', 'books_total_cents' => null,
                        'message' => $purchase === null ? 'Factura recibida que no está registrada: su crédito fiscal se está perdiendo.' : 'La compra está anulada en libros pero el documento sigue vigente en la SAT.'];
                }

                continue;
            }
            if ($booksTotal !== null && abs($booksTotal - (int) $record->total_cents) > 1) {
                $issues[] = [...$base, 'kind' => 'amount_mismatch', 'severity' => 'medium', 'books_total_cents' => $booksTotal,
                    'message' => 'El total registrado no coincide con el del documento en la SAT.'];
            } elseif ($purchase->status === 'draft') {
                $issues[] = [...$base, 'kind' => 'pending_approval', 'severity' => 'low', 'books_total_cents' => $booksTotal,
                    'message' => 'Registrada en borrador: apruébala para que entre al Libro de Compras.'];
            }
        }
        if ($records->isNotEmpty()) {
            foreach ($purchases as $purchase) {
                if (isset($matched[(int) $purchase->id]) || $purchase->status === 'void' || ! str_starts_with((string) $purchase->document_date, $period)
                    || ! in_array($purchase->fiscal_document_type, ['FACT', 'FCAM', 'FPEQ', 'FESP'], true) || blank($purchase->fiscal_authorization)) {
                    continue;
                }
                $issues[] = ['kind' => 'not_in_sat', 'severity' => 'medium', 'direction' => 'received', 'authorization' => (string) $purchase->fiscal_authorization,
                    'document' => $this->label((string) $purchase->fiscal_document_type, $purchase->fiscal_series, $purchase->fiscal_number),
                    'counterparty' => trim($purchase->supplier_name.' · NIT '.($purchase->supplier_tax_id ?? '—')), 'date' => (string) $purchase->document_date,
                    'sat_total_cents' => null, 'books_total_cents' => (int) $purchase->total_cents,
                    'message' => 'La SAT no lista este documento como recibido por la empresa: revisa la autorización o si se emitió a otro NIT.'];
            }
        }

        return $issues;
    }

    /**
     * @param  Collection<int, stdClass>  $records
     * @return list<SatIssue>
     */
    private function sales(int $companyId, int $year, int $month, Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }
        $rows = collect($this->books->book($companyId, $year, $month)['sales']);
        $issues = [];
        $matched = [];
        foreach ($records as $record) {
            $authorization = Str::upper((string) $record->authorization);
            $row = $rows->first(fn (array $sale): bool => Str::upper((string) $sale['authorization']) === $authorization);
            $base = ['direction' => 'issued', 'authorization' => (string) $record->authorization, 'document' => $this->label((string) $record->document_type, $record->series, $record->number),
                'counterparty' => trim(($record->receiver_name ?? 'Consumidor final').' · '.($record->receiver_tax_id ?? 'CF')), 'date' => (string) $record->issued_on,
                'sat_total_cents' => (int) $record->total_cents];
            if ($row !== null) {
                $matched[$row['key']] = true;
            }
            if ($record->status === 'annulled') {
                if ($row !== null && $row['status'] !== 'annulled') {
                    $issues[] = [...$base, 'kind' => 'annulled_in_sat', 'severity' => 'critical', 'books_total_cents' => (int) $row['total_cents'],
                        'message' => 'La factura está anulada en la SAT pero sigue vigente en el Libro de Ventas.'];
                }

                continue;
            }
            if ($row === null) {
                $issues[] = [...$base, 'kind' => 'missing_in_books', 'severity' => 'high', 'books_total_cents' => null,
                    'message' => 'Venta emitida en la SAT que no está en el Libro de Ventas: el IVA de esa venta no se está declarando.'];
            } elseif ($record->currency === 'GTQ' && abs((int) $row['total_cents'] - (int) $record->total_cents) > 1) {
                $issues[] = [...$base, 'kind' => 'amount_mismatch', 'severity' => 'medium', 'books_total_cents' => (int) $row['total_cents'],
                    'message' => 'El total del Libro de Ventas no coincide con el de la SAT.'];
            }
        }
        foreach ($rows as $row) {
            if (isset($matched[$row['key']]) || $row['status'] === 'annulled' || blank($row['authorization'])) {
                continue;
            }
            $issues[] = ['kind' => 'not_in_sat', 'severity' => 'medium', 'direction' => 'issued', 'authorization' => (string) $row['authorization'],
                'document' => $this->label($row['document_type'], $row['series'], $row['number']), 'counterparty' => trim($row['name'].' · '.($row['tax_id'] ?? 'CF')),
                'date' => $row['date'], 'sat_total_cents' => null, 'books_total_cents' => (int) $row['total_cents'],
                'message' => 'La SAT no lista esta venta: revisa que el listado cargado esté completo o que la autorización sea correcta.'];
        }

        return $issues;
    }

    /** @param  Collection<int, stdClass>  $purchases */
    private function find(Collection $purchases, string $authorization, ?string $taxId, ?string $series, ?string $number, string $kind): ?stdClass
    {
        $byAuthorization = $purchases->first(fn (stdClass $purchase): bool => Str::upper((string) $purchase->fiscal_authorization) === Str::upper($authorization));
        if ($byAuthorization !== null || $number === null || $kind !== 'purchase') {
            return $byAuthorization;
        }
        $nit = SatDteImportService::nit($taxId);

        return $purchases->first(fn (stdClass $purchase): bool => $nit !== null && SatDteImportService::nit($purchase->supplier_tax_id) === $nit
            && Str::upper((string) $purchase->fiscal_number) === Str::upper($number) && Str::upper((string) $purchase->fiscal_series) === Str::upper((string) $series));
    }

    /** The purchase total in the currency of the DTE, to compare it with the SAT. */
    private function purchaseTotal(stdClass $purchase, string $currency): ?int
    {
        if ($currency === 'GTQ') {
            return (int) $purchase->total_cents;
        }

        return $purchase->currency === $currency && $purchase->foreign_total_cents !== null ? (int) $purchase->foreign_total_cents : null;
    }

    private function label(string $type, ?string $series, ?string $number): string
    {
        return trim($type.' '.trim(($series ?? '').'-'.($number ?? ''), '-'));
    }
}
