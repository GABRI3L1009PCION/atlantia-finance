<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Factura electrónica simulada</title>
<style>
@page { margin: 27px 28px 54px; }
* { box-sizing: border-box; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #171b24; margin: 0; }
.test-notice { border: 1.5px solid #a84b08; background: #fff8e8; color: #8a3900; padding: 8px 10px; margin-bottom: 12px; text-align: center; font-weight: bold; letter-spacing: .3px; }
.header, .receiver { width: 100%; border-collapse: separate; border-spacing: 0; }
.header td { vertical-align: top; }
.brand { width: 31%; padding-right: 12px; }
.brand img { max-width: 185px; max-height: 82px; }
.issuer { width: 40%; padding: 5px 12px 0 0; line-height: 1.45; }
.issuer strong { font-size: 12px; }
.fel-box { width: 29%; border: 1px solid #20242c; border-radius: 8px; padding: 10px 12px; line-height: 1.45; }
.fel-box .title { font-size: 11px; font-weight: bold; text-align: center; margin-bottom: 5px; }
.receiver { margin-top: 13px; }
.receiver td { border: 1px solid #20242c; padding: 8px 10px; vertical-align: top; line-height: 1.45; }
.receiver .customer { width: 72%; border-radius: 8px 0 0 8px; }
.receiver .meta { width: 28%; border-left: 0; border-radius: 0 8px 8px 0; }
.label { display: inline-block; min-width: 76px; font-weight: bold; }
.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
.items th { background: #640000; color: white; padding: 7px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; }
.items th:first-child { border-radius: 8px 0 0 0; }
.items th:last-child { border-radius: 0 8px 0 0; }
.items td { border-right: 1px solid #343943; border-bottom: 1px solid #343943; padding: 7px 8px; vertical-align: top; }
.items td:first-child { border-left: 1px solid #343943; text-align: center; }
.items .quantity { width: 13%; }
.items .description { width: 67%; }
.items .value { width: 20%; text-align: right; }
.spacer td { padding: 0; }
.tax-note { text-align: center; font-weight: bold; padding: 7px; border-left: 1px solid #343943; border-right: 1px solid #343943; }
.total-table { width: 100%; border-collapse: collapse; }
.total-table td { border: 1px solid #343943; padding: 6px 8px; }
.total-words { width: 70%; }
.total-label { width: 15%; text-align: right; font-weight: bold; font-size: 10px; }
.total-value { width: 15%; text-align: right; font-weight: bold; font-size: 10px; }
.certification { border: 1px solid #343943; border-top: 0; padding: 6px 8px; line-height: 1.5; }
.summary { page-break-inside: avoid; }
.status-cancelled { margin-top: 7px; border: 1px solid #991b1b; color: #991b1b; padding: 7px; font-weight: bold; }
.footer { position: fixed; bottom: -35px; left: 0; right: 0; border-top: 1px solid #640000; padding-top: 6px; color: #640000; font-size: 7.5px; }
.footer-right { float: right; }
</style>
</head>
<body>
@php
    $lineCount = $document->lines->count();
    $spacerHeight = $lineCount <= 6 ? max(150, 365 - ($lineCount * 34)) : 0;
    $displaySeries = $document->fel_series ?: $document->series;
    $displayNumber = $document->fel_number ?: str_pad((string) $document->number, 8, '0', STR_PAD_LEFT);
    $certifiedAt = $document->certified_at?->timezone('America/Guatemala');
@endphp

<div class="footer">
    FEL SIMULADO - NO VÁLIDO ANTE SAT - {{ $document->series }}-{{ $document->number }}
    <span class="footer-right">Documento electrónico generado por Atlantia Finance</span>
</div>
<div class="test-notice">AMBIENTE DE PRUEBA - DOCUMENTO FEL SIMULADO - NO VÁLIDO ANTE SAT</div>

<table class="header"><tr>
    <td class="brand">
        @if (!empty($document->fiscal_snapshot['logo_data_uri']))
            <img src="{{ $document->fiscal_snapshot['logo_data_uri'] }}" alt="Logo del emisor">
        @else
            <strong style="font-size: 18px; color: #640000;">{{ $document->fiscal_snapshot['name'] }}</strong>
        @endif
    </td>
    <td class="issuer">
        <strong>{{ $document->fiscal_snapshot['name'] }}</strong><br>
        {{ $document->fiscal_snapshot['address'] ?? '' }}<br>
        Guatemala<br>
        NIT: {{ $document->fiscal_snapshot['nit'] }}
    </td>
    <td class="fel-box">
        <div class="title">FACTURA ELECTRÓNICA</div>
        <strong>SERIE:</strong> {{ $displaySeries }}<br>
        <strong>NO.:</strong> {{ $displayNumber }}<br>
        <strong>EMISOR:</strong> {{ $document->fiscal_snapshot['name'] }}<br>
        <strong>NIT:</strong> {{ $document->fiscal_snapshot['nit'] }}
    </td>
</tr></table>

<table class="receiver"><tr>
    <td class="customer">
        <span class="label">CLIENTE:</span> {{ $document->receiver_snapshot['name'] }}<br>
        <span class="label">DIRECCIÓN:</span> {{ $document->receiver_snapshot['address'] }}
    </td>
    <td class="meta">
        <strong>NIT:</strong> {{ $document->receiver_snapshot['nit'] }}<br>
        <strong>FECHA:</strong> {{ $document->issued_on->format('d/m/Y') }}
    </td>
</tr></table>

<table class="items">
    <thead><tr><th class="quantity">Cantidad</th><th class="description">Descripción</th><th class="value">Valor</th></tr></thead>
    <tbody>
    @foreach ($document->lines as $line)
        <tr>
            <td>{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
            <td><strong>{{ $line->item }}</strong><br>{{ $line->description }}</td>
            <td class="value">{{ \App\Services\Fel\FelPdfService::money($line->total_cents) }}</td>
        </tr>
    @endforeach
    @if ($spacerHeight > 0)
        <tr class="spacer"><td style="height: {{ $spacerHeight }}px;"></td><td></td><td></td></tr>
    @endif
    </tbody>
</table>
<div class="tax-note">IVA incluido: {{ \App\Services\Fel\FelPdfService::money($document->tax_cents) }}</div>

<div class="summary">
    <table class="total-table"><tr>
        <td class="total-words"><strong>TOTAL EN LETRAS:</strong><br>{{ \App\Services\Fel\FelPdfService::amountInWords($document->total_cents) }}</td>
        <td class="total-label">TOTAL: GTQ</td>
        <td class="total-value">{{ \App\Services\Fel\FelPdfService::money($document->total_cents) }}</td>
    </tr></table>
    <div class="certification">
        <strong>Número de autorización:</strong> {{ $document->authorization_number ?: 'PENDIENTE' }}<br>
        <strong>Fecha de certificación:</strong> {{ $certifiedAt?->format('d/m/Y H:i:s') ?: 'Pendiente de certificación simulada' }}<br>
        <strong>Certificador:</strong> SIMULADOR ATLANTIA - Ambiente de prueba
    </div>
    @if ($document->cancellation_reason)
        <div class="status-cancelled">DOCUMENTO ANULADO: {{ $document->cancellation_reason }}</div>
    @endif
    @if ($document->observations)
        <p><strong>Observaciones:</strong> {{ $document->observations }}</p>
    @endif
</div>
</body>
</html>
