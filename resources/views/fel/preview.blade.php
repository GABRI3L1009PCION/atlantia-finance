<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ejemplo de factura electrónica</title>
<style>
@page { margin: 34px 38px 56px; }
* { box-sizing: border-box; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1d2230; margin: 0; }
table { border-collapse: collapse; }
.wine { color: #7a1f3d; }
.head { width: 100%; }
.head td { vertical-align: top; }
.wordmark { font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 30px; color: #7a1f3d; letter-spacing: -.6px; border-bottom: 1.5px solid #7a1f3d; padding-bottom: 3px; display: inline-block; }
.tagline { font-size: 12px; color: #3a4150; margin: 5px 0 0 34px; }
.doc-title { text-align: right; white-space: nowrap; }
.doc-title .big { font-size: 27px; font-weight: bold; color: #7a1f3d; letter-spacing: .5px; }
.doc-title .sub { font-size: 9.5px; margin-top: 4px; }
.info { width: 100%; margin-top: 22px; }
.info td { vertical-align: top; }
.issuer { width: 52%; padding-right: 16px; line-height: 1.7; }
.issuer .name { font-size: 16px; font-weight: bold; color: #7a1f3d; margin-bottom: 2px; }
.meta { border-left: 1px solid #7a1f3d; padding-left: 16px; line-height: 1.75; }
.k { font-weight: bold; }
.bar { background: #7a1f3d; color: #fff; font-weight: bold; font-size: 11px; letter-spacing: .6px; padding: 7px 12px; margin-top: 22px; }
.receiver { width: 100%; margin-top: 10px; }
.receiver td { padding: 2.5px 4px; vertical-align: top; }
.receiver .k { width: 80px; }
.items { width: 100%; margin-top: 16px; border: 1px solid #b9bcc6; }
.items th { background: #7a1f3d; color: #fff; padding: 8px 6px; font-size: 10px; border: 1px solid #fff; }
.items td { border: 1px solid #b9bcc6; padding: 9px 8px; }
.c { text-align: center; }
.r { text-align: right; }
.totals { width: 100%; margin-top: 34px; }
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
.note { margin-top: 12px; font-size: 9px; }
.cert { width: 100%; }
.cert td { vertical-align: top; }
.cert .k { width: 140px; padding: 2.5px 0; }
.qr { text-align: center; width: 120px; font-size: 8.5px; font-weight: bold; }
.footer { position: fixed; bottom: -36px; left: 0; right: 0; border-top: 1px solid #7a1f3d; padding-top: 8px; text-align: center; font-size: 8.5px; letter-spacing: .3px; line-height: 1.6; }
</style>
</head>
<body>
@php
    $money = fn (int $cents): string => 'Q '.number_format($cents / 100, 2, '.', ',');
@endphp

<table class="head"><tr>
    <td>
        <div class="wordmark">Atlantia Finance</div>
        <div class="tagline">Soluciones tecnológicas y financieras</div>
    </td>
    <td class="doc-title"><div class="big">FACTURA</div><div class="sub">Documento Tributario Electrónico · FEL</div></td>
</tr></table>

<table class="info"><tr>
    <td class="issuer">
        <div class="name">ATLANTIA FINANCE</div>
        <span class="k">Emisor:</span> {{ $issuer['name'] }}<br>
        <span class="k">NIT:</span> {{ $issuer['nit'] }}<br>
        <span class="k">Establecimiento:</span> {{ $issuer['establishment'] }}<br>
        {{ $issuer['address'] }}
    </td>
    <td class="meta">
        <span class="k">Fecha de emisión:</span> {{ $issuedAt }}<br>
        <span class="k">Moneda:</span> GTQ<br>
        <span class="k">Serie:</span> {{ $series }}<br>
        <span class="k">Número de DTE:</span> {{ $number }}<br>
        <span class="k">Número de autorización:</span><br>
        {{ $authorization }}
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
        <th style="width:9%">Cant.</th><th style="width:12%">Tipo</th><th>Descripción</th><th style="width:14%">P. unitario</th><th style="width:13%">Descuento</th><th style="width:13%">Total</th>
    </tr></thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td class="c">{{ $line['quantity'] }}</td>
            <td class="c">{{ $line['type'] }}</td>
            <td>{{ $line['description'] }}</td>
            <td class="r">{{ $money($line['unit_cents']) }}</td>
            <td class="r">{{ $money($line['discount_cents']) }}</td>
            <td class="r">{{ $money($line['total_cents']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals"><tr>
    <td class="fill"></td>
    <td class="box">
        <table class="tl">
            <tr><td class="k">Base imponible:</td><td class="r">{{ $money($baseCents) }}</td></tr>
            <tr><td class="k">IVA incluido:</td><td class="r">{{ $money($vatCents) }}</td></tr>
            <tr><td class="k">Descuentos:</td><td class="r">{{ $money($discountCents) }}</td></tr>
        </table>
        <table class="tl grand"><tr><td>TOTAL A PAGAR</td><td class="r">{{ $money($totalCents) }}</td></tr></table>
    </td>
</tr></table>

<div class="words">
    <div class="t">Monto en letras:</div>
    <div class="v">{{ $totalInWords }}.</div>
</div>

<div class="rule">
    <div class="h">Observaciones</div>
    {{ $observation }}<br>
    <span class="k">Forma de pago:</span> {{ $paymentMethod }}
    <div class="note">Ejemplo ilustrativo: régimen general de IVA.</div>
</div>

<div class="rule">
    <div class="h">Datos de certificación</div>
    <table class="cert"><tr>
        <td>
            <table>
                <tr><td class="k">Certificador:</td><td>{{ $certifier['name'] }}</td></tr>
                <tr><td class="k">NIT del certificador:</td><td>{{ $certifier['nit'] }}</td></tr>
                <tr><td class="k">Fecha de certificación:</td><td>{{ $certifiedAt }}</td></tr>
            </table>
        </td>
        <td class="qr"><img src="{{ $qr }}" width="84" height="84" alt=""><br>QR ilustrativo</td>
    </tr></table>
</div>

<div class="footer">
    EJEMPLO VISUAL · DATOS FICTICIOS · SIN VALIDEZ FISCAL<br>
    Documento generado por Atlantia Finance
</div>
</body>
</html>
