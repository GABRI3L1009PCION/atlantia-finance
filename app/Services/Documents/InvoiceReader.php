<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Services\Fiscal\DocumentVatCalculator;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads a supplier invoice to prefill a purchase: the FEL XML exactly, the text of a PDF, or a photo or scan through OCR
 * (Tesseract on the same server; no document leaves it). Nothing is saved here: the accountant reviews what was read
 * and confirms the purchase, so a misread value never reaches the books on its own.
 *
 * @phpstan-type InvoiceLine array{description: string, quantity: string, unit_price: string, tax_treatment: string}
 * @phpstan-type InvoiceData array{source: string, supplier: array{tax_id: string|null, name: string|null, id: int|null}, receiver_tax_id: string|null,
 *     fiscal_document_type: string|null, fiscal_series: string|null, fiscal_number: string|null, fiscal_authorization: string|null,
 *     document_date: string|null, currency: string, total: string|null, vat: string|null, lines: list<InvoiceLine>, review: list<string>, warnings: list<string>}
 */
class InvoiceReader
{
    private const MONTHS = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    /** @return InvoiceData */
    public function read(UploadedFile $file): array
    {
        $extension = Str::lower((string) $file->getClientOriginalExtension());
        $path = (string) $file->getRealPath();

        return match (true) {
            $extension === 'xml' => $this->fromXml((string) file_get_contents($path)),
            $extension === 'pdf' => $this->fromPdf($path),
            default => $this->fromText($this->ocr($path), 'ocr'),
        };
    }

    /**
     * What a company needs on top of the reading: its registered supplier with that NIT, whether the invoice was issued
     * to another NIT and whether it was already registered.
     *
     * @param  InvoiceData  $data
     * @return InvoiceData
     */
    public function forCompany(int $companyId, ?string $companyTaxId, array $data): array
    {
        $nit = $this->nit($data['supplier']['tax_id']);
        if ($nit !== null) {
            $supplier = DB::table('purchase_suppliers')->where('company_id', $companyId)->get(['id', 'tax_id'])
                ->first(fn (object $row): bool => $this->nit((string) $row->tax_id) === $nit);
            $data['supplier']['id'] = $supplier === null ? null : (int) $supplier->id;
            if ($supplier === null) {
                $data['warnings'][] = 'El proveedor con NIT '.$data['supplier']['tax_id'].' no está registrado: regístralo y luego selecciónalo.';
            }
        }
        $receiver = $this->nit($data['receiver_tax_id']);
        if ($receiver !== null && $this->nit($companyTaxId) !== null && $receiver !== $this->nit($companyTaxId)) {
            $data['warnings'][] = 'La factura está a nombre del NIT '.$data['receiver_tax_id'].', que no es el de esta empresa.';
        }
        $documents = DB::table('purchase_documents')->where('company_id', $companyId)->where('status', '<>', 'void');
        $duplicate = match (true) {
            $data['fiscal_authorization'] !== null => $documents->where('fiscal_authorization', $data['fiscal_authorization'])->exists(),
            $data['fiscal_series'] !== null && $data['fiscal_number'] !== null && $data['supplier']['id'] !== null => $documents->where('purchase_supplier_id', $data['supplier']['id'])
                ->where('fiscal_series', $data['fiscal_series'])->where('fiscal_number', $data['fiscal_number'])->exists(),
            default => false,
        };
        if ($duplicate) {
            $data['warnings'][] = 'Esta factura ya está registrada en las compras de la empresa.';
        }

        return $data;
    }

    /** @return InvoiceData */
    public function fromXml(string $xml): array
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw ValidationException::withMessages(['file' => 'El archivo XML no es seguro y no se puede leer.']);
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $emitter = $loaded ? $this->element($xpath, 'Emisor') : null;
        if ($emitter === null) {
            throw ValidationException::withMessages(['file' => 'El XML no es una factura electrónica (FEL) de la SAT.']);
        }

        $data = $this->empty('xml');
        $general = $this->element($xpath, 'DatosGenerales');
        $authorization = $this->element($xpath, 'NumeroAutorizacion');
        $type = $general?->getAttribute('Tipo') ?: null;
        $data['supplier'] = ['tax_id' => $emitter->getAttribute('NITEmisor') ?: null, 'name' => $emitter->getAttribute('NombreEmisor') ?: ($emitter->getAttribute('NombreComercial') ?: null), 'id' => null];
        $data['receiver_tax_id'] = $this->element($xpath, 'Receptor')?->getAttribute('IDReceptor') ?: null;
        $data['fiscal_document_type'] = $this->purchaseType($type, $data['warnings']);
        $data['fiscal_series'] = $authorization?->getAttribute('Serie') ?: null;
        $data['fiscal_number'] = $authorization?->getAttribute('Numero') ?: null;
        $data['fiscal_authorization'] = $authorization === null ? null : (Str::upper(trim($authorization->textContent)) ?: null);
        $data['document_date'] = $this->isoDate($general?->getAttribute('FechaHoraEmision'));
        $data['currency'] = in_array($general?->getAttribute('CodigoMoneda'), ['GTQ', 'USD', 'EUR'], true) ? (string) $general->getAttribute('CodigoMoneda') : 'GTQ';
        $data['total'] = $this->amount($this->element($xpath, 'GranTotal')?->textContent);
        $vat = null;
        foreach ($this->elements($xpath, 'TotalImpuesto') as $tax) {
            if (Str::upper($tax->getAttribute('NombreCorto')) === 'IVA') {
                $vat = $this->amount($tax->getAttribute('TotalMontoImpuesto'));
            }
        }
        $data['vat'] = $vat;
        foreach (array_slice($this->elements($xpath, 'Item'), 0, 100) as $item) {
            $data['lines'][] = $this->xmlLine($xpath, $item, $type);
        }
        if ($data['lines'] === [] && $data['total'] !== null) {
            $data['lines'][] = $this->summaryLine($data);
        }
        if ($authorization === null) {
            $data['warnings'][] = 'El XML no trae la certificación de la SAT: confirma que la factura fue emitida.';
        }

        return $data;
    }

    /** @return InvoiceData */
    public function fromText(string $text, string $source): array
    {
        $plain = $this->plain($text);
        if (mb_strlen(trim($plain)) < 20) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer texto en el archivo. Prueba con una foto más nítida o sube el XML de la factura.']);
        }
        $data = $this->empty($source);
        $data['review'] = ['supplier', 'fiscal_series', 'fiscal_number', 'document_date', 'lines'];

        $issuer = null;
        $receiver = null;
        preg_match_all('/(.{0,30})\bN\.?\s?I\.?\s?T\.?\b(?:\s*(?:del\s+)?(?:emisor|receptor|comprador|cliente|vendedor|proveedor)\b)?[^0-9A-Z\n]{0,25}([0-9]{1,12}-?[0-9K]|C\s?\/?\s?F)\b/i', $plain, $nits, PREG_SET_ORDER);
        foreach ($nits as [$all, $before, $value]) {
            $isReceiver = preg_match('/recept|compr|client|adquir/i', $before.$all) === 1;
            if ($isReceiver && $receiver === null) {
                $receiver = Str::upper(str_replace(' ', '', $value));
            } elseif (! $isReceiver && $issuer === null && preg_match('/^C\s?\/?\s?F$/i', $value) !== 1) {
                $issuer = Str::upper($value);
            }
        }
        $data['supplier']['tax_id'] = $issuer;
        $data['receiver_tax_id'] = $receiver;
        $data['supplier']['name'] = $this->supplierName($text);

        if (preg_match('/\b([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})\b/i', $plain, $uuid) === 1) {
            $data['fiscal_authorization'] = Str::upper($uuid[1]);
        }
        if (preg_match('/\bserie\b[^A-Z0-9\n]{0,15}([A-Z0-9]{4,10})\b/i', $plain, $series) === 1) {
            $data['fiscal_series'] = Str::upper($series[1]);
        }
        if (preg_match('/\b(?:numero|no\.)(?:\s+de)?(?:\s+(?:dte|documento|factura))?[^0-9\n]{0,10}(\d{5,12})\b/i', $plain, $number) === 1) {
            $data['fiscal_number'] = $number[1];
        }
        // A FEL authorization carries its series (first 8 hex digits) and number (the next 8, in decimal).
        if ($data['fiscal_authorization'] !== null && ($data['fiscal_series'] === null || $data['fiscal_number'] === null)) {
            $hex = str_replace('-', '', $data['fiscal_authorization']);
            $data['fiscal_series'] ??= substr($hex, 0, 8);
            $data['fiscal_number'] ??= (string) hexdec(substr($hex, 8, 8));
        }

        $data['fiscal_document_type'] = $this->purchaseType(match (true) {
            preg_match('/factura\s+cambiaria/i', $plain) === 1 => 'FCAM',
            preg_match('/peque\w*\s+contribuyente/i', $plain) === 1 => 'FPEQ',
            preg_match('/nota\s+de\s+credito/i', $plain) === 1 => 'NCRE',
            preg_match('/nota\s+de\s+debito/i', $plain) === 1 => 'NDEB',
            preg_match('/factura\s+especial/i', $plain) === 1 => 'FESP',
            default => 'FACT',
        }, $data['warnings']);
        $data['currency'] = match (true) {
            preg_match('/\bUSD\b|US\s?\$|dolar/i', $plain) === 1 => 'USD',
            preg_match('/\bEUR\b|€|\beuros?\b/i', $plain) === 1 => 'EUR',
            default => 'GTQ',
        };
        $data['document_date'] = $this->textDate($plain);
        [$data['total'], $data['vat']] = $this->textTotals($plain);
        if ($data['total'] !== null) {
            $data['lines'][] = $this->summaryLine($data);
        } else {
            $data['warnings'][] = 'No se encontró el total de la factura: escríbelo en las líneas.';
        }
        $data['warnings'][] = 'Datos leídos de '.($source === 'ocr' ? 'una imagen' : 'un PDF').': revisa cada campo antes de guardar.';

        return $data;
    }

    /** @return InvoiceData */
    private function fromPdf(string $path): array
    {
        $result = Process::timeout($this->timeout())->run([(string) config('services.ocr.pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-']);
        if ($result->successful() && mb_strlen(trim($result->output())) >= 40) {
            return $this->fromText($result->output(), 'pdf');
        }

        // A scanned PDF has no text: its first pages go through OCR.
        $directory = storage_path('app/tmp-ocr/'.Str::uuid());
        File::ensureDirectoryExists($directory);
        try {
            $pages = Process::timeout($this->timeout())->run([(string) config('services.ocr.pdftoppm'), '-r', '300', '-png', '-f', '1', '-l', '2', $path, $directory.'/page']);
            if (! $pages->successful()) {
                throw ValidationException::withMessages(['file' => 'No se pudo abrir el PDF. Revisa que no esté dañado ni protegido con contraseña.']);
            }
            $text = '';
            foreach (File::glob($directory.'/page*.png') as $image) {
                $text .= $this->ocr($image)."\n";
            }

            return $this->fromText($text, 'ocr');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function ocr(string $path): string
    {
        try {
            $result = Process::timeout($this->timeout())->run([(string) config('services.ocr.tesseract'), $path, 'stdout', '-l', (string) config('services.ocr.languages')]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['file' => 'La lectura de imágenes tardó demasiado. Prueba con una foto más pequeña o sube el XML.']);
        }
        if (! $result->successful()) {
            throw ValidationException::withMessages(['file' => $result->exitCode() === 127
                ? 'La lectura de fotos y escaneos no está disponible en este servidor. Sube el XML o el PDF de la factura.'
                : 'No se pudo leer la imagen. Prueba con una foto más nítida o sube el XML de la factura.']);
        }

        return $result->output();
    }

    /** @return InvoiceLine */
    private function xmlLine(DOMXPath $xpath, DOMElement $item, ?string $type): array
    {
        $value = fn (string $name): ?string => ($node = $this->element($xpath, $name, $item)) === null ? null : trim($node->textContent);
        $quantity = $this->amount($value('Cantidad'), 4) ?? '1.0000';
        $total = $this->amount($value('Total')) ?? '0.00';
        $description = Str::limit(preg_replace('/\s+/', ' ', (string) $value('Descripcion')) ?: 'Producto o servicio', 230, '…');
        $vat = '0.00';
        foreach ($this->elements($xpath, 'Impuesto', $item) as $tax) {
            if (Str::upper(trim((string) $this->element($xpath, 'NombreCorto', $tax)?->textContent)) === 'IVA') {
                $vat = $this->amount($this->element($xpath, 'MontoImpuesto', $tax)?->textContent) ?? '0.00';
            }
        }
        $treatment = $item->getAttribute('BienOServicio') === 'S' ? 'services' : 'goods';
        if ($type !== 'FPEQ' && bccomp($vat, '0', 2) === 0 && bccomp($total, '0', 2) > 0) {
            $treatment = 'exempt';
        }
        // The purchase form takes whole quantities: a fractional quantity (or a price that does not divide exactly) goes as one unit.
        $whole = bccomp($quantity, bcadd($quantity, '0', 0), 4) === 0 && bccomp($quantity, '1', 0) >= 0 && bccomp($quantity, '10000', 0) <= 0;
        $unit = $whole ? bcdiv($total, $quantity, 2) : $total;
        if (! $whole || bccomp(bcmul($unit, $quantity, 2), $total, 2) !== 0) {
            return ['description' => $description.($whole && bccomp($quantity, '1', 4) === 0 ? '' : ' (cant. '.rtrim(rtrim($quantity, '0'), '.').')'), 'quantity' => '1', 'unit_price' => $total, 'tax_treatment' => $treatment];
        }

        return ['description' => $description, 'quantity' => bcadd($quantity, '0', 0), 'unit_price' => $unit, 'tax_treatment' => $treatment];
    }

    /**
     * @param  InvoiceData  $data
     * @return InvoiceLine
     */
    private function summaryLine(array $data): array
    {
        $reference = trim(($data['fiscal_series'] ?? '').'-'.($data['fiscal_number'] ?? ''), '-');
        $description = 'Compra según factura'.($reference === '' ? '' : ' '.$reference).($data['supplier']['name'] ? ' de '.$data['supplier']['name'] : '');

        return ['description' => Str::limit($description, 240, '…'), 'quantity' => '1', 'unit_price' => (string) $data['total'],
            'tax_treatment' => $data['fiscal_document_type'] === 'FPEQ' || ! is_numeric($data['vat']) || bccomp($data['vat'], '0', 2) > 0 ? 'goods' : 'exempt'];
    }

    /** @param list<string> $warnings */
    private function purchaseType(?string $type, array &$warnings): ?string
    {
        if ($type === null) {
            return null;
        }
        if (in_array($type, DocumentVatCalculator::PURCHASE_TYPES, true)) {
            return $type;
        }
        $warnings[] = match ($type) {
            'NCRE' => 'El documento es una nota de crédito, no una factura de compra: regístrala desde la compra original.',
            'NDEB' => 'El documento es una nota de débito: revisa cómo registrarla.',
            default => 'El documento es de tipo '.$type.': revisa si corresponde registrarlo como compra.',
        };

        return null;
    }

    private function supplierName(string $text): ?string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/(?:nombre|raz[oó]n\s+social)(?:\s+(?:comercial|del\s+emisor|emisor))?\s*:\s*(.{3,})/iu', $line, $match) === 1 && preg_match('/recept|client|compr/i', $line) !== 1) {
                return Str::limit(trim(preg_replace('/\s{2,}.*/u', '', $match[1]) ?? ''), 150, '');
            }
        }
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s{2,}.*/u', '', $line) ?? '');
            if (mb_strlen($line) >= 4 && preg_match('/\p{L}{3}/u', $line) === 1 && preg_match('/factura|documento|tributario|electr[oó]nic|fel\b|serie|fecha|nit/iu', $line) !== 1) {
                return Str::limit($line, 150, '');
            }
        }

        return null;
    }

    private function textDate(string $plain): ?string
    {
        $lines = preg_split('/\R/u', $plain) ?: [];
        usort($lines, fn (string $a, string $b): int => (int) (preg_match('/emision|fecha/i', $b) === 1) <=> (int) (preg_match('/emision|fecha/i', $a) === 1));
        foreach ($lines as $line) {
            if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $line, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }
            if (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $line, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (preg_match('/\b(\d{1,2})(?:\s+de\s+|[\s\-\/]+)([a-z]{3})[a-z]*\.?(?:\s+de\s+|[\s\-\/]+)(\d{4})\b/i', $line, $m) === 1
                && isset(self::MONTHS[Str::lower($m[2])]) && checkdate(self::MONTHS[Str::lower($m[2])], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], self::MONTHS[Str::lower($m[2])], $m[1]);
            }
        }

        return null;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function textTotals(string $plain): array
    {
        $total = null;
        $vat = null;
        foreach (preg_split('/\R/u', $plain) ?: [] as $line) {
            preg_match_all('/(?<![\d.,])(\d{1,3}(?:,\d{3})+|\d+)\.(\d{2})(?![\d%])/', $line, $amounts, PREG_SET_ORDER);
            if ($amounts === []) {
                continue;
            }
            $last = end($amounts);
            $value = str_replace(',', '', $last[1]).'.'.$last[2];
            if (! is_numeric($value)) {
                continue;
            }
            if (preg_match('/total/i', $line) === 1 && preg_match('/sub\s*-?\s*total|total\s+(de\s+)?(impuesto|iva|descuento)/i', $line) !== 1) {
                $total = ! is_numeric($total) || bccomp($value, $total, 2) > 0 ? $value : $total;
            } elseif (preg_match('/\bIVA\b/i', $line) === 1 && $vat === null) {
                $vat = $value;
            }
        }

        return [$total, $vat];
    }

    /** @return InvoiceData */
    private function empty(string $source): array
    {
        return ['source' => $source, 'supplier' => ['tax_id' => null, 'name' => null, 'id' => null], 'receiver_tax_id' => null, 'fiscal_document_type' => null,
            'fiscal_series' => null, 'fiscal_number' => null, 'fiscal_authorization' => null, 'document_date' => null, 'currency' => 'GTQ', 'total' => null,
            'vat' => null, 'lines' => [], 'review' => [], 'warnings' => []];
    }

    /** Text without accents, so "Número" and "Numero" match the same pattern. */
    private function plain(string $text): string
    {
        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N']);
    }

    private function nit(?string $value): ?string
    {
        $clean = ltrim(Str::upper(preg_replace('/[^0-9Kk]/', '', (string) $value) ?? ''), '0');

        return $clean === '' ? null : $clean;
    }

    private function isoDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return numeric-string|null */
    private function amount(?string $value, int $scale = 2): ?string
    {
        $value = trim(str_replace(',', '', (string) $value));

        return $value !== '' && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 && is_numeric($value) ? bcadd($value, '0', $scale) : null;
    }

    private function element(DOMXPath $xpath, string $name, ?DOMElement $context = null): ?DOMElement
    {
        $list = $xpath->query(($context === null ? '//' : './/').'*[local-name()="'.$name.'"]', $context);
        $node = $list === false ? null : $list->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /** @return list<DOMElement> */
    private function elements(DOMXPath $xpath, string $name, ?DOMElement $context = null): array
    {
        $list = $xpath->query(($context === null ? '//' : './/').'*[local-name()="'.$name.'"]', $context);
        $nodes = [];
        foreach ($list === false ? [] : $list as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function timeout(): int
    {
        return max(10, (int) config('services.ocr.timeout', 90));
    }
}
