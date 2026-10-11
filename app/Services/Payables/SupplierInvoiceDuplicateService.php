<?php

declare(strict_types=1);

namespace App\Services\Payables;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Possible duplicated supplier invoices. It never blocks by itself (a false match must be reviewable): it compares
 * supplier (or the supplier's tax id across records), fiscal series and number, date, amount and currency, and each
 * pair becomes an incident that a person confirms (void the duplicate) or dismisses as not duplicated.
 */
class SupplierInvoiceDuplicateService
{
    /**
     * @param  list<int>  $companyIds
     * @return list<array{key: string, company_id: int, level: string, level_label: string, reason: string, documents: list<array{id: int, document: string, supplier: string, date: string, total_cents: int, status: string, destination: string}>}>
     */
    public function candidates(array $companyIds): array
    {
        $documents = DB::table('purchase_documents')->join('companies', 'companies.id', '=', 'purchase_documents.company_id')->whereIn('purchase_documents.company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('purchase_documents.status', ['draft', 'approved'])
            ->get(['purchase_documents.id', 'purchase_documents.public_id', 'companies.public_id as company_public_id', 'purchase_documents.company_id', 'purchase_documents.purchase_supplier_id', 'purchase_documents.supplier_name', 'purchase_documents.supplier_tax_id', 'purchase_documents.number',
                'purchase_documents.external_reference', 'purchase_documents.fiscal_series', 'purchase_documents.fiscal_number', 'purchase_documents.document_date', 'purchase_documents.total_cents', 'purchase_documents.currency', 'purchase_documents.status']);
        $pairs = [];
        foreach ($documents->groupBy('company_id') as $companyDocuments) {
            $list = $companyDocuments->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    [$level, $reason] = $this->compare($list[$i], $list[$j]);
                    if ($level === null) {
                        continue;
                    }
                    $ids = [(int) $list[$i]->id, (int) $list[$j]->id];
                    sort($ids);
                    $pairs[] = [
                        'key' => 'duplicate_invoice:'.$ids[0].':'.$ids[1],
                        'company_id' => (int) $list[$i]->company_id,
                        'level' => $level,
                        'level_label' => $level === 'strong' ? 'Muy probable' : 'Posible',
                        'reason' => $reason,
                        'documents' => [$this->row($list[$i]), $this->row($list[$j])],
                    ];
                }
            }
        }

        return $pairs;
    }

    /** @return array{0: string|null, 1: string} */
    private function compare(stdClass $a, stdClass $b): array
    {
        $sameSupplier = (int) $a->purchase_supplier_id === (int) $b->purchase_supplier_id
            || ($a->supplier_tax_id !== null && $a->supplier_tax_id !== '' && mb_strtoupper((string) $a->supplier_tax_id) === mb_strtoupper((string) $b->supplier_tax_id) && mb_strtoupper((string) $a->supplier_tax_id) !== 'CF');
        if (! $sameSupplier) {
            return [null, ''];
        }
        $number = fn (stdClass $row): string => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) ($row->fiscal_number ?? ''))) ?? '';
        $series = fn (stdClass $row): string => mb_strtoupper(trim((string) ($row->fiscal_series ?? '')));
        if ($number($a) !== '' && $number($a) === $number($b) && $series($a) === $series($b)) {
            return ['strong', 'Mismo proveedor con la misma serie y número de documento fiscal'];
        }
        $reference = fn (stdClass $row): string => mb_strtoupper(trim((string) ($row->external_reference ?? '')));
        if ($reference($a) !== '' && $reference($a) === $reference($b)) {
            return ['strong', 'Mismo proveedor con la misma referencia de documento'];
        }
        if ((int) $a->total_cents === (int) $b->total_cents && (string) $a->currency === (string) $b->currency && (string) $a->document_date === (string) $b->document_date) {
            return ['possible', 'Mismo proveedor, misma fecha y mismo monto'];
        }

        return [null, ''];
    }

    /** @return array{id: int, document: string, supplier: string, date: string, total_cents: int, status: string, destination: string} */
    private function row(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'document' => trim(($row->external_reference ?: 'Compra #'.($row->number ?? 'borrador')).' '.trim(($row->fiscal_series ?? '').'-'.($row->fiscal_number ?? ''), '-')),
            'supplier' => (string) $row->supplier_name,
            'date' => (string) $row->document_date,
            'total_cents' => (int) $row->total_cents,
            'status' => $row->status === 'draft' ? 'Borrador' : 'Aprobada',
            'destination' => $row->status === 'draft' ? '/accounting/companies/'.$row->company_public_id.'/purchases/documents/'.$row->public_id : '/payables/documents/'.$row->id,
        ];
    }
}
