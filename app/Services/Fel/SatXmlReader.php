<?php

declare(strict_types=1);

namespace App\Services\Fel;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/**
 * Reads the XML that the SAT portal gives back for an issued document (the certified DTE) and extracts what Atlantia
 * needs to register it and compare it with the draft. Entities and external resources are never loaded.
 */
class SatXmlReader
{
    /**
     * @return array{authorization_number: string, series: string, number: string, certified_at: string, issuer_nit: ?string, receiver_id: ?string, total_cents: ?int, document_type: ?string}
     */
    public function read(string $xml): array
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw ValidationException::withMessages(['sat_xml' => 'El archivo XML no es seguro y no se puede leer.']);
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw ValidationException::withMessages(['sat_xml' => 'El archivo no es un XML válido. Sube el XML que descargas de la factura ya emitida en la SAT.']);
        }

        $xpath = new DOMXPath($dom);
        $authorization = $this->element($xpath, 'NumeroAutorizacion');

        if ($authorization === null || trim($authorization->textContent) === '') {
            throw ValidationException::withMessages(['sat_xml' => 'Este XML no trae la certificación de la SAT. Descarga el XML de la factura ya emitida, no el de la solicitud.']);
        }

        $timestamp = $this->element($xpath, 'FechaHoraCertificacion');
        $total = $this->element($xpath, 'GranTotal');
        $amount = $total === null ? '' : trim($total->textContent);
        $general = $this->element($xpath, 'DatosGenerales');

        return [
            'authorization_number' => trim($authorization->textContent),
            'series' => trim($authorization->getAttribute('Serie')),
            'number' => trim($authorization->getAttribute('Numero')),
            'certified_at' => $timestamp === null ? '' : trim($timestamp->textContent),
            'issuer_nit' => $this->attribute($xpath, 'Emisor', 'NITEmisor'),
            'receiver_id' => $this->attribute($xpath, 'Receptor', 'IDReceptor'),
            'total_cents' => preg_match('/^\d+(\.\d+)?$/D', $amount) === 1 && is_numeric($amount) ? (int) bcmul($amount, '100', 0) : null,
            'document_type' => $general === null ? null : ($general->getAttribute('Tipo') ?: null),
        ];
    }

    private function element(DOMXPath $xpath, string $name): ?DOMElement
    {
        $list = $xpath->query('//*[local-name()="'.$name.'"]');
        $node = $list === false ? null : $list->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function attribute(DOMXPath $xpath, string $element, string $attribute): ?string
    {
        $node = $this->element($xpath, $element);
        $value = $node?->getAttribute($attribute);

        return $value === null || $value === '' ? null : $value;
    }
}
