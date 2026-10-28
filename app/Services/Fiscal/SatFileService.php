<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Accounting\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use RuntimeException;
use stdClass;

/**
 * Load files for the SAT desktop tools, built from the books already kept in the system:
 *
 * - Asiste Libros (Libros de Compras y Ventas del IVA): text file .asl, one line per document with the 32 columns of
 *   "Datos a incluir en archivo de carga" v1.1.9, separated by "|", without titles and for a single month. Export sales
 *   carry their customs document (DUA, DUCA or FAUCA).
 * - Informe de Inventarios (semestral or at the request of Fiscalización): tab-delimited .txt with the header row of
 *   the SAT instructivo and the name SAT_RSI_<NIT>_<TIPO>_dd_mm_aa.txt.
 * - RetenISR 2 Escritorio: Excel 97-2003 workbooks with one sheet laid out as the SAT "Modelos de carga" (facturas
 *   especiales, retenciones a proveedores del régimen opcional simplificado and the anexo of rentas del trabajo).
 *
 * The SAT tools run on Windows and read the files as ANSI, so text files are written in Windows-1252 with CRLF.
 *
 * @phpstan-import-type VatRow from VatBookService
 *
 * @phpstan-type SatFile array{filename: string, content: string, mime: string, lines: int, warnings: list<string>}
 */
class SatFileService
{
    public const INVENTORY_TYPES = [
        'BDPVE' => 'Bienes disponibles para la venta',
        'PTERM' => 'Producto terminado',
        'MPRIM' => 'Materia prima',
    ];

    public const RETENISR_FILES = [
        'facturas_especiales' => 'Facturas especiales',
        'opcional_simplificado' => 'Retenciones a proveedores (opcional simplificado)',
        'rentas_trabajo' => 'Anexo de rentas del trabajo',
    ];

    /** Concept codes of the RetenISR "Tabla de Conceptos" used for the opcional simplificado withholdings. */
    public const RETENISR_CONCEPTS = [
        1 => 'Compras o servicios gravados, adquiridos de entidades exentas',
        2 => 'Materias primas',
        3 => 'Productos terminados',
        4 => 'Transporte de carga y de personas',
        5 => 'Telecomunicaciones',
        6 => 'Servicios bancarios, seguros y financieros',
        7 => 'Servicios informáticos',
        8 => 'Suministro de energía eléctrica y agua',
        9 => 'Servicios técnicos',
        10 => 'Arrendamiento de bienes muebles',
        11 => 'Arrendamiento de bienes inmuebles',
        12 => 'Servicios profesionales',
    ];

    private const INVENTORY_HEADER = ['CODIGO_PRODUCTO', 'DESCRIPCION', 'UNIDAD_DE_MEDIDA', 'CANTIDAD', 'COSTO_UNITARIO', 'COSTO_TOTAL',
        'AGRUPACION_GENERAL', 'SUB_AGRUPACION_1', 'SUB_AGRUPACION_2', 'SUB_AGRUPACION_3'];

    public function __construct(private VatBookService $books, private InventoryService $inventory) {}

    /**
     * Asiste Libros load file of one month, with the purchases and/or the sales book.
     *
     * @param  list<string>  $books  'sales' and/or 'purchases'
     * @return SatFile
     */
    public function asisteLibros(stdClass $company, int $year, int $month, array $books = ['sales', 'purchases']): array
    {
        $book = $this->books->book((int) $company->id, $year, $month);
        $establishment = $this->digits((string) (DB::table('company_fel_settings')->where('company_id', $company->id)->value('establishment_code') ?? '1')) ?: '1';
        $lines = [];
        $warnings = [];
        $customs = DB::table('sales_invoices')->where('company_id', $company->id)->whereNotNull('customs_document_number')
            ->whereIn('id', array_column(array_filter($book['sales'], fn (array $row): bool => $row['source'] === 'sales_invoice'), 'source_id') ?: [-1])
            ->get(['id', 'customs_document_type', 'customs_document_number'])->keyBy('id');
        foreach (['purchases' => 'C', 'sales' => 'V'] as $key => $letter) {
            if (! in_array($key, $books, true)) {
                continue;
            }
            foreach ($book[$key] as $row) {
                $number = $this->digits((string) ($row['number'] ?? ''));
                $label = ($letter === 'C' ? 'Compra' : 'Venta').' '.trim(($row['series'] ?? '').' '.($row['number'] ?? '')).' de '.$row['name'];
                if ($number === '') {
                    $warnings[] = $label.': no tiene número de documento y se omitió.';

                    continue;
                }
                $export = $letter === 'V' && $row['source'] === 'sales_invoice' ? $customs->get($row['source_id']) : null;
                $line = $this->asisteLine($row, $letter, $establishment, $number, $export);
                if ($line === null) {
                    $warnings[] = $label.': falta el NIT o DPI y se omitió.';

                    continue;
                }
                $lines[] = implode('|', $line);
            }
        }

        return ['filename' => sprintf('libros-iva-%04d-%02d-%s.asl', $year, $month, $this->digits((string) $company->tax_id) ?: 'empresa'),
            'content' => $this->ansi($lines), 'mime' => 'text/plain; charset=windows-1252', 'lines' => count($lines), 'warnings' => $warnings];
    }

    /**
     * Informe de Inventarios: the stock at a date valued at average cost.
     *
     * @return SatFile
     */
    public function inventoryReport(stdClass $company, string $asOf, string $type = 'BDPVE', bool $byRequest = false): array
    {
        $companyId = (int) $company->id;
        $products = DB::table('bills_of_materials')->where('company_id', $companyId)->pluck('product_item_id')->map(fn ($id): int => (int) $id)->all();
        $materials = DB::table('bill_of_material_components')->where('company_id', $companyId)->pluck('inventory_item_id')->map(fn ($id): int => (int) $id)->all();
        $lines = [implode("\t", self::INVENTORY_HEADER)];
        $warnings = [];
        foreach ($this->inventory->items($companyId, $asOf) as $item) {
            $belongs = match ($type) {
                'PTERM' => in_array($item['id'], $products, true),
                'MPRIM' => in_array($item['id'], $materials, true) && ! in_array($item['id'], $products, true),
                default => ! in_array($item['id'], $products, true) && ! in_array($item['id'], $materials, true),
            };
            if (! $belongs || $item['quantity'] <= 0) {
                continue;
            }
            $unitCost = round($item['value_cents'] / 100 / $item['quantity'], 6);
            $total = round($item['quantity'] * $unitCost, 2);
            if (abs($total * 100 - $item['value_cents']) >= 1) {
                $warnings[] = $item['code'].': el costo total del informe (Q'.number_format($total, 2).') difiere en centavos del kardex por el redondeo del costo unitario.';
            }
            $lines[] = implode("\t", [$this->text($item['code'], 100), $this->text($item['name'], 100), $this->text($item['unit'], 100),
                $this->decimal($item['quantity'], 4), $this->decimal($unitCost, 6), number_format($total, 2, '.', ''), '', '', '', '']);
        }
        $date = Carbon::parse($asOf);

        return ['filename' => sprintf('%s_%s_%s_%s.txt', $byRequest ? 'SAT_PF' : 'SAT_RSI', $this->digits((string) $company->tax_id) ?: 'NIT', $type, $date->format('d_m_y')),
            'content' => $this->ansi($lines), 'mime' => 'text/plain; charset=windows-1252', 'lines' => count($lines) - 1, 'warnings' => $warnings];
    }

    /**
     * RetenISR 2 load workbook of one month.
     *
     * @return SatFile
     */
    public function retenIsr(stdClass $company, string $kind, int $year, int $month): array
    {
        [$headers, $rows, $warnings] = match ($kind) {
            'facturas_especiales' => $this->facturasEspeciales((int) $company->id, $year, $month),
            'opcional_simplificado' => $this->opcionalSimplificado((int) $company->id, $year, $month),
            default => $this->rentasTrabajo((int) $company->id, $year, $month),
        };
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet()->setTitle(mb_substr(self::RETENISR_FILES[$kind] ?? 'Carga', 0, 31));
        foreach ([$headers, ...$rows] as $r => $row) {
            foreach ($row as $c => $value) {
                $cell = [$c + 1, $r + 1];
                if ($value instanceof Carbon) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel($value));
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif (is_float($value) || is_int($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No fue posible preparar el archivo de RetenISR.');
        }
        (new Xls($workbook))->save($stream);
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return ['filename' => sprintf('retenisr-%s-%04d-%02d-%s.xls', str_replace('_', '-', $kind), $year, $month, $this->digits((string) $company->tax_id) ?: 'empresa'),
            'content' => $content, 'mime' => 'application/vnd.ms-excel', 'lines' => count($rows), 'warnings' => $warnings];
    }

    /**
     * What each file would hold for a month, to show before downloading.
     *
     * @return array{asiste: array{lines: int, warnings: list<string>}, retenisr: array<string, array{lines: int, warnings: list<string>}>}
     */
    public function summary(stdClass $company, int $year, int $month): array
    {
        $asiste = $this->asisteLibros($company, $year, $month);
        $retenisr = [];
        foreach (array_keys(self::RETENISR_FILES) as $kind) {
            [, $rows, $warnings] = match ($kind) {
                'facturas_especiales' => $this->facturasEspeciales((int) $company->id, $year, $month),
                'opcional_simplificado' => $this->opcionalSimplificado((int) $company->id, $year, $month),
                default => $this->rentasTrabajo((int) $company->id, $year, $month),
            };
            $retenisr[$kind] = ['lines' => count($rows), 'warnings' => $warnings];
        }

        return ['asiste' => ['lines' => $asiste['lines'], 'warnings' => $asiste['warnings']], 'retenisr' => $retenisr];
    }

    /**
     * The 32 columns of one document, or null when it lacks the counterpart's identification. An export sale ($export,
     * with its DUA, DUCA or FAUCA) is transaction "E" and its values go to the columns of operations abroad.
     *
     * @param  VatRow  $row
     * @return list<string>|null
     */
    private function asisteLine(array $row, string $letter, string $establishment, string $number, ?stdClass $export = null): ?array
    {
        $type = $this->asisteDocument($row, $letter);
        $taxId = strtoupper((string) preg_replace('/[\s-]+/', '', (string) ($row['tax_id'] ?? '')));
        $identityType = '';
        $identityNumber = '';
        // A factura especial is issued to a person without NIT: SAT identifies the seller by the DPI (CUI of 13 digits).
        if ($type === 'FE' && preg_match('/^\d{13}$/', $taxId) === 1) {
            [$identityType, $identityNumber, $taxId] = ['DPI', $taxId, ''];
        }
        if ($taxId === '' && $identityNumber === '' && $export === null) {
            return null;
        }

        $annulled = $row['status'] === 'annulled';
        $goods = abs($row['taxable_goods_cents']);
        $services = abs($row['taxable_services_cents']);
        $vat = abs($row['vat_cents']);
        $goodsVat = $goods + $services === 0 ? $vat : intdiv($vat * $goods + intdiv($goods + $services, 2), $goods + $services);
        $exempt = abs($row['exempt_cents']);
        $exemptIsService = $services > $goods;
        $small = abs($row['small_taxpayer_cents']);
        $isSmall = $letter === 'C' && in_array($type, ['FPC', 'FPE'], true);
        $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
        $local = [$money($goods + $goodsVat), $money($services + $vat - $goodsVat), $money($exemptIsService ? 0 : $exempt), $money($exemptIsService ? $exempt : 0)];
        $abroad = ['0.00', '0.00', '0.00', '0.00'];
        if ($export !== null) {
            [$local, $abroad] = [$abroad, $local];
        }
        $taxable = $isSmall ? array_fill(0, 8, '') : [$local[0], $abroad[0], $local[1], $abroad[1], $local[2], $abroad[2], $local[3], $abroad[3]];
        $smallTaxpayer = $isSmall ? [$money($small + $goods + $services + $exempt), '0.00', '0.00', '0.00'] : ['', '', '', ''];

        return [
            $establishment,
            $letter,
            $type,
            $this->text((string) ($row['series'] ?? ''), 50),
            mb_substr($number, -25),
            Carbon::parse($row['date'])->format('d/m/Y'),
            mb_substr($taxId, 0, 15),
            $this->text($row['name'], 100),
            $export === null ? 'L' : 'E',
            $export === null ? '' : ($services + ($exemptIsService ? $exempt : 0) > $goods + ($exemptIsService ? 0 : $exempt) ? 'SERVICIO' : 'BIEN'),
            $letter === 'C' ? '' : ($annulled ? 'A' : 'E'),
            $identityType,
            $identityNumber,
            $export === null ? '' : (string) $export->customs_document_type,
            $export === null ? '' : $this->text((string) $export->customs_document_number, 50),
            ...$taxable,
            '', '', '',
            ...$smallTaxpayer,
            $money($isSmall ? 0 : $vat),
            $money($annulled ? 0 : abs($row['total_cents'])),
        ];
    }

    /**
     * SAT document code of column 3. Documents with a FEL authorization are the electronic variants.
     *
     * @param  VatRow  $row
     */
    private function asisteDocument(array $row, string $letter): string
    {
        $electronic = filled($row['authorization']) || $row['source'] === 'fel_document';

        return match ($row['document_type']) {
            'NCRE' => 'NC',
            'NDEB' => 'ND',
            'FESP' => 'FE',
            'FPEQ' => $letter === 'C' ? ($electronic ? 'FPE' : 'FPC') : ($electronic ? 'FCE' : 'FC'),
            default => $electronic ? 'FCE' : 'FC',
        };
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: list<string>} */
    private function facturasEspeciales(int $companyId, int $year, int $month): array
    {
        $rows = [];
        $warnings = [];
        foreach ($this->supplierWithholdings($companyId, $year, $month, true) as $row) {
            $id = $this->digits((string) $row->supplier_tax_id);
            if ($id === '') {
                $warnings[] = 'Factura especial '.$this->documentLabel($row).': falta el DPI del vendedor.';
            }
            $rows[] = ['DPI', $id, $this->text((string) $row->supplier_name, 100), '', (int) $row->taxable_services_cents > (int) $row->taxable_goods_cents ? 'SERVICIO' : 'BIEN',
                (string) ($row->fiscal_series ?? ''), $this->digits((string) ($row->fiscal_number ?? $row->external_reference ?? '')), Carbon::parse($row->document_date),
                round((int) $row->base_cents / 100, 2), round((int) $row->amount_cents / 100, 2)];
        }
        if ($rows !== []) {
            $warnings[] = 'Completa el domicilio de cada vendedor en la columna D antes de cargar el archivo: el sistema no lo guarda.';
        }

        return [['Tipo de Documento (DPI, Cédula o Partida de Nacimiento)', 'Número de Documento de Identificación', 'Nombres y Apellidos', 'Domicilio', 'Concepto',
            'Serie Factura', 'Número de Factura ', 'Fecha Factura', 'Valor Facturado (BASE)', 'Retención'], $rows, $warnings];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: list<string>} */
    private function opcionalSimplificado(int $companyId, int $year, int $month): array
    {
        $rows = [];
        $warnings = [];
        foreach ($this->supplierWithholdings($companyId, $year, $month, false) as $row) {
            $nit = strtoupper((string) preg_replace('/[\s-]+/', '', (string) $row->supplier_tax_id));
            if ($nit === '' || $nit === 'CF') {
                $warnings[] = 'Retención a '.$row->supplier_name.' ('.$this->documentLabel($row).'): falta el NIT del proveedor.';
            }
            $rows[] = [$nit, $this->text((string) $row->supplier_name, 100), Carbon::parse($row->document_date), (string) ($row->fiscal_series ?? ''),
                $this->digits((string) ($row->fiscal_number ?? $row->external_reference ?? '')), round((int) $row->base_cents / 100, 2),
                (int) $row->taxable_services_cents > (int) $row->taxable_goods_cents ? 12 : 3];
        }
        if ($rows !== []) {
            $warnings[] = 'El concepto se propuso como 3 (productos terminados) o 12 (servicios profesionales) según el desglose; revísalo con la tabla de conceptos de RetenISR.';
        }

        return [['NIT Proveedor (sin guión)', 'Nombre', 'Fecha de Factura o Documento', 'Serie de Factura o Documento', 'Número de Factura o Documento',
            'Valor Facturado (valor base para calcular la retención)', 'Concepto'], $rows, $warnings];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>, 2: list<string>} */
    private function rentasTrabajo(int $companyId, int $year, int $month): array
    {
        $results = DB::table('payroll_results as r')->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')->join('payroll_employees as e', 'e.id', '=', 'r.payroll_employee_id')
            ->join('payroll_result_lines as l', 'l.payroll_result_id', '=', 'r.id')
            ->leftJoin('payroll_concepts as c', fn ($join) => $join->on('c.company_id', '=', 'r.company_id')->on('c.code', '=', 'l.concept_code'))
            ->where('r.company_id', $companyId)->whereIn('p.status', ['approved', 'paid', 'closed'])->whereYear('p.ends_on', $year)->whereMonth('p.ends_on', $month)
            ->groupBy('e.id', 'e.nit', 'e.first_name', 'e.last_name')
            ->selectRaw("e.id, e.nit, e.first_name, e.last_name,
                CAST(COALESCE(SUM(l.amount_cents) FILTER (WHERE l.type = 'earning' AND c.affects_isr), 0) AS BIGINT) as base_cents,
                CAST(COALESCE(SUM(l.amount_cents) FILTER (WHERE l.concept_code = 'ISR'), 0) AS BIGINT) as isr_cents")
            ->orderBy('e.last_name')->orderBy('e.first_name')->get();
        $rows = [];
        $warnings = [];
        foreach ($results as $row) {
            if ((int) $row->isr_cents <= 0) {
                continue;
            }
            $name = trim($row->first_name.' '.$row->last_name);
            $nit = strtoupper((string) preg_replace('/[\s-]+/', '', (string) ($row->nit ?? '')));
            if ($nit === '') {
                $warnings[] = $name.': falta el NIT del empleado.';
            }
            $rows[] = [$nit, $this->text($name, 100), round((int) $row->base_cents / 100, 2), round((int) $row->isr_cents / 100, 2)];
        }

        return [['NIT empleado', 'Nombre', 'Base Gravada o Pagada', 'Retencion '], $rows, $warnings];
    }

    /** @return list<stdClass> */
    private function supplierWithholdings(int $companyId, int $year, int $month, bool $facturaEspecial): array
    {
        $query = DB::table('purchase_payment_withholdings as w')->join('purchase_payments as p', 'p.id', '=', 'w.purchase_payment_id')
            ->join('purchase_documents as d', 'd.id', '=', 'p.purchase_document_id')->where('w.company_id', $companyId)->where('w.kind', 'isr')
            ->whereNull('p.voided_at')->whereYear('p.paid_on', $year)->whereMonth('p.paid_on', $month);
        $facturaEspecial ? $query->where('d.fiscal_document_type', FacturaEspecialService::TYPE) : $query->where('d.fiscal_document_type', '<>', FacturaEspecialService::TYPE);

        return array_values($query->orderBy('p.paid_on')->orderBy('w.id')->get(['w.base_cents', 'w.amount_cents', 'd.supplier_name', 'd.supplier_tax_id', 'd.fiscal_series', 'd.fiscal_number',
            'd.external_reference', 'd.number', 'd.document_date', 'd.taxable_goods_cents', 'd.taxable_services_cents'])->all());
    }

    private function documentLabel(stdClass $row): string
    {
        return trim(($row->fiscal_series ?? '').' '.($row->fiscal_number ?? '')) ?: '#'.$row->number;
    }

    /** @param  list<string>  $lines */
    private function ansi(array $lines): string
    {
        return $lines === [] ? '' : (string) mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'Windows-1252', 'UTF-8');
    }

    /** Free text without the separators the SAT tools reject, cut to the column size. */
    private function text(string $value, int $size): string
    {
        return mb_substr(trim((string) preg_replace('/[|\t\r\n]+/', ' ', $value)), 0, $size);
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private function decimal(float $value, int $places): string
    {
        $formatted = rtrim(rtrim(number_format($value, $places, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
