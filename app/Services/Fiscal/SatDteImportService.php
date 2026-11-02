<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\PurchaseDocumentDraftService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use stdClass;
use Throwable;
use ZipArchive;

/**
 * Loads in bulk the electronic documents (DTE) the SAT reports for a company: the listing the office downloads from the
 * Agencia Virtual (CSV or Excel), the XML files of the DTE, or a ZIP with either. Each document is kept once by its
 * authorization number; the company's NIT decides whether it was issued or received, and documents of other NITs are
 * ignored. The received invoices not yet in the books can then be registered as purchase drafts in one step.
 *
 * @phpstan-type DteRow array{authorization: string, document_type: string, series: string|null, number: string|null, issued_on: string, issuer_tax_id: string|null, issuer_name: string|null, receiver_tax_id: string|null, receiver_name: string|null, currency: string, total_cents: int, vat_cents: int|null, status: string, annulled_on: string|null}
 */
class SatDteImportService
{
    public const MAX_ROWS = 20000;

    /** DTE type names of the SAT listing and their codes. Longer names first, so "Factura Cambiaria" is not read as "Factura". */
    private const TYPE_NAMES = [
        'factura cambiaria pequeno contribuyente' => 'FCAP', 'factura pequeno contribuyente' => 'FPEQ', 'factura cambiaria' => 'FCAM',
        'factura especial' => 'FESP', 'nota de credito' => 'NCRE', 'nota de debito' => 'NDEB', 'nota de abono' => 'NABN',
        'recibo por donacion' => 'RDON', 'recibo' => 'RECI', 'factura' => 'FACT',
    ];

    /** Column names of the SAT listing (accents removed, lower case) for each field; the first match wins. */
    private const COLUMNS = [
        'authorization' => ['numero de autorizacion', 'autorizacion', 'uuid'],
        'document_type' => ['tipo de dte', 'tipo dte', 'tipo de documento', 'tipo'],
        'series' => ['serie del dte', 'serie'],
        'number' => ['numero del dte', 'numero de dte', 'numero dte', 'numero'],
        'issued_on' => ['fecha de emision', 'fecha emision', 'fecha'],
        'issuer_tax_id' => ['nit del emisor', 'nit emisor'],
        'issuer_name' => ['nombre completo del emisor', 'nombre del emisor', 'nombre emisor'],
        'receiver_tax_id' => ['id del receptor', 'nit del receptor', 'id receptor', 'nit receptor'],
        'receiver_name' => ['nombre completo del receptor', 'nombre del receptor', 'nombre receptor'],
        'currency' => ['moneda'],
        'total' => ['gran total', 'monto total', 'total'],
        'vat' => ['iva (monto de este impuesto)', 'iva'],
        'annulled_mark' => ['marca de anulado', 'anulado'],
        'annulled_on' => ['fecha de anulacion'],
        'status' => ['estado'],
    ];

    public function __construct(
        private AccountingService $accounting,
        private PurchaseDocumentDraftService $drafts,
    ) {}

    /**
     * Reads the documents of one uploaded file.
     *
     * @return list<DteRow>
     */
    public function parse(string $path, string $fileName): array
    {
        $extension = Str::lower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => $this->fromTable($this->csvRows((string) file_get_contents($path))),
            'xlsx', 'xls' => $this->fromTable($this->spreadsheetRows($path)),
            'xml' => array_values(array_filter([$this->fromXml((string) file_get_contents($path))])),
            'zip' => $this->fromZip($path),
            default => throw ValidationException::withMessages(['files' => 'Sube el listado de DTE en CSV o Excel, los XML o un ZIP con ellos.']),
        };
    }

    /**
     * Saves the documents of the company: new ones are added, known ones get their latest status.
     *
     * @param  list<DteRow>  $rows
     * @return array{issued: int, received: int, annulled: int, added: int, updated: int, ignored: int, periods: list<string>}
     */
    public function import(Organization $organization, User $user, stdClass $company, array $rows): array
    {
        $companyNit = self::nit($company->tax_id ?? null);
        if ($companyNit === null) {
            throw ValidationException::withMessages(['files' => 'Registra el NIT de la empresa antes de cargar sus DTE.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['files' => 'Carga como máximo '.number_format(self::MAX_ROWS).' documentos a la vez.']);
        }
        $result = ['issued' => 0, 'received' => 0, 'annulled' => 0, 'added' => 0, 'updated' => 0, 'ignored' => 0, 'periods' => []];

        DB::transaction(function () use ($organization, $user, $company, $rows, $companyNit, &$result): void {
            $this->accounting->lock((int) $company->id);
            foreach ($rows as $row) {
                $direction = match ($companyNit) {
                    self::nit($row['issuer_tax_id']) => 'issued',
                    self::nit($row['receiver_tax_id']) => 'received',
                    default => null,
                };
                if ($direction === null) {
                    $result['ignored']++;

                    continue;
                }
                $values = [...$row, 'direction' => $direction, 'period' => substr($row['issued_on'], 0, 7), 'imported_by' => $user->id, 'updated_at' => now()];
                $existing = DB::table('sat_dte_records')->where('company_id', $company->id)->where('authorization', $row['authorization'])->value('id');
                if ($existing === null) {
                    DB::table('sat_dte_records')->insert([...$values, 'organization_id' => $organization->id, 'company_id' => $company->id, 'created_at' => now()]);
                    $result['added']++;
                } else {
                    DB::table('sat_dte_records')->where('id', $existing)->update($values);
                    $result['updated']++;
                }
                $result[$direction]++;
                $result['annulled'] += $row['status'] === 'annulled' ? 1 : 0;
                $result['periods'][$values['period']] = $values['period'];
            }
            $result['periods'] = array_values($result['periods']);
            sort($result['periods']);
            $this->accounting->audit($organization, $user, 'fiscal.sat_dte.imported', 'companies', (int) $company->id, array_diff_key($result, ['periods' => true]));
        });

        return $result;
    }

    /**
     * Registers as purchase drafts the valid invoices received in the month that are not in the books yet, creating the
     * suppliers that do not exist. Each one gets a single line with its total on the chosen account; the accountant
     * reviews and approves them as usual. Credit notes and documents that cannot be drafted are reported, not guessed.
     *
     * @return array{created: int, linked: int, skipped: list<array{authorization: string, document: string, reason: string}>}
     */
    public function registerPurchases(Organization $organization, User $user, stdClass $company, string $period, int $accountId, string $treatment): array
    {
        $result = ['created' => 0, 'linked' => 0, 'skipped' => []];
        $records = DB::table('sat_dte_records')->where('company_id', $company->id)->where('period', $period)->where('direction', 'received')
            ->where('status', 'valid')->whereNull('purchase_document_id')->orderBy('issued_on')->orderBy('id')->get();

        foreach ($records as $record) {
            $label = trim(($record->series ?? '').'-'.($record->number ?? ''), '-') ?: (string) $record->authorization;
            $existing = DB::table('purchase_documents')->where('company_id', $company->id)->where('status', '<>', 'void')
                ->whereRaw('UPPER(fiscal_authorization) = ?', [Str::upper((string) $record->authorization)])->value('id');
            if ($existing !== null) {
                DB::table('sat_dte_records')->where('id', $record->id)->update(['purchase_document_id' => $existing, 'updated_at' => now()]);
                $result['linked']++;

                continue;
            }
            if (! in_array($record->document_type, DocumentVatCalculator::PURCHASE_TYPES, true)) {
                $result['skipped'][] = ['authorization' => (string) $record->authorization, 'document' => $record->document_type.' '.$label,
                    'reason' => $record->document_type === 'NCRE' ? 'Las notas de crédito se registran desde la compra que corrigen.' : 'Este tipo de documento no genera una compra.'];

                continue;
            }
            try {
                $supplierId = $this->supplier($company, $record);
                $requestId = (string) Str::uuid();
                $this->drafts->create($organization, $user, $company, [
                    'request_id' => $requestId, 'supplier_id' => $supplierId, 'document_type' => 'purchase', 'document_date' => (string) $record->issued_on,
                    'due_on' => (string) $record->issued_on, 'currency' => (string) $record->currency,
                    'lines' => [['account_id' => $accountId, 'description' => 'Según DTE '.$label.' de '.($record->issuer_name ?: 'el proveedor'), 'quantity' => 1,
                        'unit_price' => number_format(((int) $record->total_cents) / 100, 2, '.', ''), 'tax_treatment' => $treatment]],
                    'fiscal_document_type' => (string) $record->document_type, 'fiscal_series' => $record->series, 'fiscal_number' => $record->number,
                    'fiscal_authorization' => (string) $record->authorization,
                ], ['source' => 'sat_dte_import']);
                $purchaseId = DB::table('purchase_documents')->where('company_id', $company->id)->where('public_id', $requestId)->value('id');
                DB::table('sat_dte_records')->where('id', $record->id)->update(['purchase_document_id' => $purchaseId, 'updated_at' => now()]);
                $result['created']++;
            } catch (ValidationException $exception) {
                $result['skipped'][] = ['authorization' => (string) $record->authorization, 'document' => $record->document_type.' '.$label,
                    'reason' => (string) collect($exception->errors())->flatten()->first()];
            }
        }
        $this->accounting->audit($organization, $user, 'fiscal.sat_dte.purchases_registered', 'companies', (int) $company->id,
            ['period' => $period, 'created' => $result['created'], 'linked' => $result['linked'], 'skipped' => count($result['skipped'])]);

        return $result;
    }

    /** A NIT reduced to its digits and the K check digit, so "1234567-8", "1234567 8" and "12345678" are the same. */
    public static function nit(?string $value): ?string
    {
        $nit = preg_replace('/[^0-9K]/', '', Str::upper((string) $value)) ?? '';
        $nit = ltrim($nit, '0');

        return $nit === '' ? null : $nit;
    }

    /** The company's supplier with the NIT of the document, created when the company has none. */
    private function supplier(stdClass $company, stdClass $record): int
    {
        $nit = self::nit($record->issuer_tax_id);
        $supplier = $nit === null ? null : DB::table('purchase_suppliers')->where('company_id', $company->id)->get(['id', 'tax_id'])
            ->first(fn (stdClass $row): bool => self::nit($row->tax_id) === $nit);
        if ($supplier !== null) {
            return (int) $supplier->id;
        }

        return (int) DB::table('purchase_suppliers')->insertGetId([
            'company_id' => $company->id, 'name' => mb_substr((string) ($record->issuer_name ?: 'Proveedor NIT '.$record->issuer_tax_id), 0, 150),
            'tax_id' => $record->issuer_tax_id === null ? null : mb_substr((string) $record->issuer_tax_id, 0, 30), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<list<mixed>>  $table  rows of cells, the first one with the column names
     * @return list<DteRow>
     */
    private function fromTable(array $table): array
    {
        $header = array_shift($table);
        if ($header === null) {
            return [];
        }
        $columns = $this->columns(array_map(fn (mixed $cell): string => self::plain((string) $cell), $header));
        if (! isset($columns['authorization'], $columns['issued_on'], $columns['total'])) {
            throw ValidationException::withMessages(['files' => 'El archivo no parece el listado de DTE de la SAT: faltan las columnas de autorización, fecha de emisión o gran total.']);
        }
        $rows = [];
        foreach ($table as $cells) {
            $value = fn (string $field): ?string => isset($columns[$field]) && trim((string) ($cells[$columns[$field]] ?? '')) !== '' ? trim((string) $cells[$columns[$field]]) : null;
            $authorization = Str::upper((string) $value('authorization'));
            $date = $this->date($value('issued_on'));
            if ($authorization === '' || $date === null) {
                continue;
            }
            $annulled = $this->isYes($value('annulled_mark')) || str_contains(self::plain((string) $value('status')), 'anulad') || $value('annulled_on') !== null;
            $currency = Str::upper((string) ($value('currency') ?? 'GTQ'));
            $rows[] = [
                'authorization' => mb_substr($authorization, 0, 64), 'document_type' => $this->type((string) $value('document_type')),
                'series' => $this->code($value('series')), 'number' => $this->code($value('number')), 'issued_on' => $date,
                'issuer_tax_id' => $value('issuer_tax_id'), 'issuer_name' => $this->name($value('issuer_name')),
                'receiver_tax_id' => $value('receiver_tax_id'), 'receiver_name' => $this->name($value('receiver_name')),
                'currency' => in_array($currency, ['GTQ', 'USD', 'EUR'], true) ? $currency : 'GTQ',
                'total_cents' => abs($this->cents($value('total')) ?? 0), 'vat_cents' => $this->cents($value('vat')),
                'status' => $annulled ? 'annulled' : 'valid', 'annulled_on' => $annulled ? $this->date($value('annulled_on')) : null,
            ];
        }

        return $rows;
    }

    /**
     * Position of each field among the column names: an exact name first, then a column that starts with it.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $found = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                foreach ([fn (string $column): bool => $column === $name, fn (string $column): bool => str_starts_with($column, $name)] as $matches) {
                    foreach ($header as $index => $column) {
                        if (! isset($found[$field]) && ! in_array($index, $found, true) && $matches($column)) {
                            $found[$field] = $index;
                        }
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<list<string>> */
    private function csvRows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $first = $lines[0] ?? '';
        $delimiter = collect([',', ';', '|', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($first, $candidate))->first();
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $rows[] = array_map(fn (?string $cell): string => (string) $cell, str_getcsv($line, (string) $delimiter, '"', ''));
            }
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function spreadsheetRows(string $path): array
    {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
        } catch (Throwable) {
            throw ValidationException::withMessages(['files' => 'No se pudo leer el archivo de Excel.']);
        }

        return array_values(array_map('array_values', array_slice($sheet->toArray(null, true, true, false), 0, self::MAX_ROWS + 1)));
    }

    /** @return list<DteRow> */
    private function fromZip(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['files' => 'No se pudo abrir el ZIP.']);
        }
        $rows = [];
        for ($index = 0; $index < $zip->numFiles && count($rows) <= self::MAX_ROWS; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
            $stat = $zip->statIndex($index);
            if (! in_array($extension, ['xml', 'csv', 'txt'], true) || str_contains($name, '__MACOSX') || $stat === false || $stat['size'] > 20 * 1024 * 1024) {
                continue;
            }
            $content = (string) $zip->getFromIndex($index);
            $rows = [...$rows, ...($extension === 'xml' ? array_values(array_filter([$this->fromXml($content)])) : $this->fromTable($this->csvRows($content)))];
        }
        $zip->close();

        return $rows;
    }

    /**
     * One DTE from its certified XML. Anything that is not a certified DTE is skipped.
     *
     * @return DteRow|null
     */
    private function fromXml(string $xml): ?array
    {
        if ($xml === '' || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            return null;
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $find = function (string $name) use ($xpath): ?DOMElement {
            $node = $xpath->query('//*[local-name()="'.$name.'"]')?->item(0);

            return $node instanceof DOMElement ? $node : null;
        };
        $emitter = $find('Emisor');
        $authorization = $find('NumeroAutorizacion');
        $general = $find('DatosGenerales');
        $date = $this->date($general?->getAttribute('FechaHoraEmision'));
        if ($emitter === null || $authorization === null || $date === null || trim($authorization->textContent) === '') {
            return null;
        }
        $receiver = $find('Receptor');
        $vat = null;
        foreach ($xpath->query('//*[local-name()="TotalImpuesto"]') ?: [] as $tax) {
            if ($tax instanceof DOMElement && Str::upper($tax->getAttribute('NombreCorto')) === 'IVA') {
                $vat = $this->cents($tax->getAttribute('TotalMontoImpuesto'));
            }
        }
        $currency = Str::upper($general?->getAttribute('CodigoMoneda') ?: 'GTQ');

        return [
            'authorization' => mb_substr(Str::upper(trim($authorization->textContent)), 0, 64), 'document_type' => $this->type((string) $general?->getAttribute('Tipo')),
            'series' => $this->code($authorization->getAttribute('Serie')), 'number' => $this->code($authorization->getAttribute('Numero')), 'issued_on' => $date,
            'issuer_tax_id' => $emitter->getAttribute('NITEmisor') ?: null, 'issuer_name' => $this->name($emitter->getAttribute('NombreEmisor') ?: $emitter->getAttribute('NombreComercial')),
            'receiver_tax_id' => $receiver?->getAttribute('IDReceptor') ?: null, 'receiver_name' => $this->name($receiver?->getAttribute('NombreReceptor')),
            'currency' => in_array($currency, ['GTQ', 'USD', 'EUR'], true) ? $currency : 'GTQ', 'total_cents' => abs($this->cents($find('GranTotal')?->textContent) ?? 0),
            'vat_cents' => $vat, 'status' => 'valid', 'annulled_on' => null,
        ];
    }

    private function type(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Z]{4}$/', Str::upper($value)) === 1) {
            return Str::upper($value);
        }
        $plain = self::plain($value);
        foreach (self::TYPE_NAMES as $name => $code) {
            if (str_contains($plain, $name)) {
                return $code;
            }
        }

        return 'OTRO';
    }

    /** A date of the listing (dd/mm/yyyy, ISO or an Excel serial number) as Y-m-d. */
    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
                return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
            }
            if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/', $value, $match) === 1) {
                return CarbonImmutable::create((int) $match[3], (int) $match[2], (int) $match[1])->toDateString();
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) === 1) {
                return CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3])->toDateString();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** An amount of the listing ("1,234.56", "Q 1234.5", "-12.00") in cents. */
    private function cents(?string $value): ?int
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', trim((string) $value))) ?? '';
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }
        $negative = str_starts_with($clean, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($clean, '-'), 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = ((int) $whole) * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $negative ? -$cents : $cents;
    }

    private function isYes(?string $value): bool
    {
        return in_array(self::plain((string) $value), ['si', 's', 'true', '1', 'x', 'anulado', 'yes'], true);
    }

    private function code(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr(Str::upper($value), 0, 40);
    }

    private function name(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 200);
    }

    /** Lower case without accents or repeated spaces, to compare column names and values. */
    private static function plain(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))) ?? '');
    }
}
