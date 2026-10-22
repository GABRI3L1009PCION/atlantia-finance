<?php

declare(strict_types=1);

namespace App\Services\Fel;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A sample invoice in the layout Atlantia's electronic invoices will have, filled with made-up data (issuer, series,
 * authorization number, certifier...). It is only to check how a sent invoice looks: it has no fiscal value.
 */
class FelPreviewService
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @param  array{name?: string, nit?: string, address?: string}  $receiver
     */
    public function pdf(array $receiver = []): string
    {
        $issued = CarbonImmutable::now('America/Guatemala');
        $lines = [
            ['quantity' => 1, 'type' => 'Servicio', 'description' => 'Suscripción mensual Atlantia Finance', 'unit_cents' => 15000, 'discount_cents' => 0, 'total_cents' => 15000],
            ['quantity' => 1, 'type' => 'Servicio', 'description' => 'Configuración inicial de plataforma', 'unit_cents' => 10000, 'discount_cents' => 0, 'total_cents' => 10000],
        ];
        $total = array_sum(array_column($lines, 'total_cents'));
        $base = (int) round($total / 1.12);
        $series = 'DEMO'.$issued->year;
        $number = '00000125';

        $html = view('fel.preview', [
            'issuer' => ['name' => 'Atlantia Sistemas de Ejemplo, S.A.', 'nit' => '0000000-0', 'establishment' => '1 · Oficina central', 'address' => 'Puerto Barrios, Izabal, Guatemala'],
            'issuedAt' => $issued->format('d/m/Y · H:i:s'),
            'series' => $series,
            'number' => $number,
            'authorization' => $series.'-0000-0000-0000-000000000125',
            'receiver' => ['name' => $receiver['name'] ?? 'Comercial El Puerto — Ejemplo', 'nit' => $receiver['nit'] ?? 'CF', 'address' => $receiver['address'] ?? 'Puerto Barrios, Izabal'],
            'lines' => $lines,
            'baseCents' => $base,
            'vatCents' => $total - $base,
            'discountCents' => array_sum(array_column($lines, 'discount_cents')),
            'totalCents' => $total,
            'totalInWords' => $this->inWords($total),
            'observation' => 'Servicios correspondientes a '.self::MONTHS[$issued->month - 1].' de '.$issued->year.'.',
            'paymentMethod' => 'Transferencia bancaria.',
            'certifier' => ['name' => 'Certificador de Ejemplo, S.A.', 'nit' => '0000000-0'],
            'certifiedAt' => $issued->addSeconds(3)->format('d/m/Y · H:i:s'),
            'qr' => FelPdfService::qrDataUri('EJEMPLO ILUSTRATIVO - SIN VALIDEZ FISCAL - '.$series.'-'.$number),
        ])->render();

        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    private function inWords(int $cents): string
    {
        $whole = intdiv($cents, 100);
        $words = Number::spell($whole, locale: 'es');
        if ($words === false) {
            throw new RuntimeException('No fue posible convertir el total a letras.');
        }
        $fraction = $cents % 100;

        return Str::upper($words).' '.($whole === 1 ? 'QUETZAL' : 'QUETZALES').($fraction === 0 ? ' EXACTOS' : ' CON '.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT).'/100');
    }
}
