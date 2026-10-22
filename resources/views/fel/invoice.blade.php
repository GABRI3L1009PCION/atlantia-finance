<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Factura electrónica</title>
<style>
@page { margin: 34px 38px 56px; }
* { box-sizing: border-box; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1d2230; margin: 0; }
table { border-collapse: collapse; }
.notice { border: 1.5px solid #a84b08; background: #fff8e8; color: #8a3900; padding: 7px 10px; margin-bottom: 12px; text-align: center; font-weight: bold; letter-spacing: .3px; }
.cancelled { border: 1.5px solid #991b1b; background: #fef2f2; color: #991b1b; padding: 7px 10px; margin-bottom: 12px; text-align: center; font-weight: bold; }
.head { width: 100%; }
.head td { vertical-align: top; }
.wordmark { font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 24px; color: #7a1f3d; letter-spacing: -.4px; }
.logo { max-width: 200px; max-height: 70px; }
.doc-title { text-align: right; white-space: nowrap; }
.doc-title .big { font-size: 27px; font-weight: bold; color: #7a1f3d; letter-spacing: .5px; }
.doc-title .sub { font-size: 9.5px; margin-top: 4px; }
.info { width: 100%; margin-top: 20px; }
.info td { vertical-align: top; }
.issuer { width: 52%; padding-right: 16px; line-height: 1.7; }
.issuer .name { font-size: 15px; font-weight: bold; color: #7a1f3d; margin-bottom: 2px; }
.meta { border-left: 1px solid #7a1f3d; padding-left: 16px; line-height: 1.75; }
.k { font-weight: bold; }
.bar { background: #7a1f3d; color: #fff; font-weight: bold; font-size: 11px; letter-spacing: .6px; padding: 7px 12px; margin-top: 20px; }
.receiver { width: 100%; margin-top: 10px; }
.receiver td { padding: 2.5px 4px; vertical-align: top; }
.receiver .k { width: 80px; }
.items { width: 100%; margin-top: 16px; border: 1px solid #b9bcc6; }
.items th { background: #7a1f3d; color: #fff; padding: 8px 6px; font-size: 10px; border: 1px solid #fff; }
.items td { border: 1px solid #b9bcc6; padding: 9px 8px; vertical-align: top; }
.c { text-align: center; }
.r { text-align: right; }
.summary { page-break-inside: avoid; }
.totals { width: 100%; margin-top: 26px; }
.totals td.fill { width: 56%; }
.totals .box { width: 44%; }
.tl { width: 100%; }
.tl td { padding: 4px 6px; }
.grand { background: #7a1f3d; color: #fff; font-size: 14px; font-weight: bold; }
.grand td { padding: 11px 12px; }
.words { margin-top: 16px; }
.words .t { font-weight: bold; }
.words .v { font-size: 11px; margin-top: 4px; }
.rule { border-top: 1px solid #7a1f3d; margin-top: 18px; padding-top: 12px; }
.h { color: #7a1f3d; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
.cert { width: 100%; }
.cert td { vertical-align: top; }
.cert .k { width: 150px; padding: 2.5px 0; }
.qr { text-align: center; width: 120px; font-size: 8.5px; font-weight: bold; }
.qr-empty { width: 84px; height: 84px; border: 1px solid #1d2230; text-align: center; padding-top: 30px; font-size: 8px; font-weight: normal; margin: 0 auto; }
.footer { position: fixed; bottom: -36px; left: 0; right: 0; border-top: 1px solid #7a1f3d; padding-top: 8px; text-align: center; font-size: 8.5px; letter-spacing: .3px; line-height: 1.6; }
</style>
</head>
<body>
@php
    use App\Services\Fel\FelPdfService;

    $simulated = $document->provider === 'mock';
    $fiscal = $document->fiscal_snapshot;
    $receiver = $document->receiver_snapshot;
    $series = $document->fel_series ?: $document->series;
    $number = $document->fel_number ?: str_pad((string) $document->number, 8, '0', STR_PAD_LEFT);
    $certifiedAt = $document->certified_at?->timezone('America/Guatemala');
    $issuedAt = $certifiedAt !== null ? $document->issued_on->format('d/m/Y').' · '.$certifiedAt->format('H:i:s') : $document->issued_on->format('d/m/Y');
    $money = fn (int $cents): string => 'Q '.number_format($cents / 100, 2, '.', ',');
    $baseCents = $document->total_cents - $document->tax_cents;
    $verifyUrl = $document->authorization_number && ! $simulated
        ? 'https://felpub.c.sat.gob.gt/verificador-web/publico/vistas/verificacionDte.jsf?tipo=autorizacion&numero='.$document->authorization_number
            .'&emisor='.preg_replace('/\W/', '', (string) ($fiscal['nit'] ?? '')).'&receptor='.preg_replace('/\W/', '', (string) ($receiver['nit'] ?? '')).'&monto='.number_format($document->total_cents / 100, 2, '.', '')
        : null;
    $qr = $verifyUrl !== null ? FelPdfService::qrDataUri($verifyUrl) : ($simulated ? FelPdfService::qrDataUri('FEL SIMULADO - NO VALIDO ANTE SAT - '.$document->series.'-'.$document->number) : null);
    $types = ['service' => 'Servicio', 'goods' => 'Bien'];
@endphp

<div class="footer">
    @if ($simulated)
        FEL SIMULADO - NO VÁLIDO ANTE SAT · {{ $document->series }}-{{ $document->number }}<br>
        Documento electrónico generado por Atlantia Finance
    @else
        Representación informativa del documento emitido en la SAT · {{ $document->series }}-{{ $document->number }}<br>
        La factura válida es la emitida por la SAT · Generado por Atlantia Finance
    @endif
</div>

@if ($simulated)
    <div class="notice">AMBIENTE DE PRUEBA - DOCUMENTO FEL SIMULADO - NO VÁLIDO ANTE SAT</div>
@endif
@if ($document->cancellation_reason)
    <div class="cancelled">DOCUMENTO ANULADO: {{ $document->cancellation_reason }}</div>
@endif

<table class="head"><tr>
    <td>
        @if (! empty($fiscal['logo_data_uri']))
            <img class="logo" src="{{ $fiscal['logo_data_uri'] }}" alt="Logo del emisor">
        @else
            <div class="wordmark">{{ $fiscal['name'] }}</div>
        @endif
    </td>
    <td class="doc-title"><div class="big">FACTURA</div><div class="sub">Documento Tributario Electrónico · FEL</div></td>
</tr></table>

<table class="info"><tr>
    <td class="issuer">
        <div class="name">{{ $fiscal['name'] }}</div>
        <span class="k">NIT:</span> {{ $fiscal['nit'] }}<br>
        @if (! empty($fiscal['address']))
            {{ $fiscal['address'] }}<br>
        @endif
        Guatemala
    </td>
    <td class="meta">
        <span class="k">Fecha de emisión:</span> {{ $issuedAt }}<br>
        <span class="k">Moneda:</span> {{ $document->currency }}<br>
        <span class="k">Serie:</span> {{ $series }}<br>
        <span class="k">Número de DTE:</span> {{ $number }}<br>
        <span class="k">Número de autorización:</span><br>
        {{ $document->authorization_number ?: 'PENDIENTE' }}
    </td>
</tr></table>

<div class="bar">DATOS DEL RECEPTOR</div>
<table class="receiver">
    <tr><td class="k">Nombre:</td><td>{{ $receiver['name'] }}</td></tr>
    <tr><td class="k">NIT:</td><td>{{ $receiver['nit'] }}</td></tr>
    <tr><td class="k">Dirección:</td><td>{{ $receiver['address'] }}</td></tr>
</table>

<table class="items">
    <thead><tr>
        <th style="width:9%">Cant.</th><th style="width:11%">Tipo</th><th>Descripción</th><th style="width:14%">P. unitario</th><th style="width:12%">Descuento</th><th style="width:14%">Total</th>
    </tr></thead>
    <tbody>
    @foreach ($document->lines as $line)
        @php $quantity = (float) $line->quantity; @endphp
        <tr>
            <td class="c">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
            <td class="c">{{ $types[$line->classification] ?? ucfirst((string) $line->classification) }}</td>
            <td><strong>{{ $line->item }}</strong>@if ($line->description)<br>{{ $line->description }}@endif</td>
            <td class="r">{{ $money((int) round(($line->total_cents + $line->discount_cents) / max($quantity, 0.0001))) }}</td>
            <td class="r">{{ $money($line->discount_cents) }}</td>
            <td class="r">{{ $money($line->total_cents) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="summary">
    <table class="totals"><tr>
        <td class="fill"></td>
        <td class="box">
            <table class="tl">
                <tr><td class="k">Base imponible:</td><td class="r">{{ $money($baseCents) }}</td></tr>
                <tr><td class="k">IVA incluido:</td><td class="r">{{ $money($document->tax_cents) }}</td></tr>
                <tr><td class="k">Descuentos:</td><td class="r">{{ $money($document->discount_cents) }}</td></tr>
            </table>
            <table class="tl grand"><tr><td>TOTAL A PAGAR</td><td class="r">{{ $money($document->total_cents) }}</td></tr></table>
        </td>
    </tr></table>

    <div class="words">
        <div class="t">Monto en letras:</div>
        <div class="v">{{ FelPdfService::amountInWordsExact($document->total_cents) }}.</div>
    </div>

    @if ($document->observations)
        <div class="rule">
            <div class="h">Observaciones</div>
            {!! nl2br(e($document->observations)) !!}
        </div>
    @endif

    <div class="rule">
        <div class="h">Datos de certificación</div>
        <table class="cert"><tr>
            <td>
                <table>
                    <tr><td class="k">Certificador:</td><td>{{ $simulated ? 'SIMULADOR ATLANTIA - Ambiente de prueba' : 'SAT - Superintendencia de Administración Tributaria' }}</td></tr>
                    <tr><td class="k">Fecha de certificación:</td><td>{{ $certifiedAt?->format('d/m/Y · H:i:s') ?: ($simulated ? 'Pendiente de certificación simulada' : 'Pendiente de emitir en la SAT') }}</td></tr>
                </table>
            </td>
            <td class="qr">
                @if ($qr)
                    <img src="{{ $qr }}" width="84" height="84" alt=""><br>{{ $simulated ? 'QR ilustrativo' : 'Verificación SAT' }}
                @else
                    <div class="qr-empty">QR disponible<br>al certificar</div>
                @endif
            </td>
        </tr></table>
    </div>
</div>
</body>
</html>
