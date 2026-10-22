<?php

use App\Mail\FelPreviewMail;
use App\Services\Fel\FelPreviewService;
use Illuminate\Support\Facades\Mail;

test('la vista previa genera un PDF de una página con los datos de ejemplo', function () {
    $pdf = app(FelPreviewService::class)->pdf();

    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(5000)
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe(1);
});

test('el ejemplo trae el total, el IVA incluido y la leyenda sin validez fiscal', function () {
    $html = view('fel.preview', [
        'issuer' => ['name' => 'Atlantia Sistemas de Ejemplo, S.A.', 'nit' => '0000000-0', 'establishment' => '1 · Oficina central', 'address' => 'Puerto Barrios, Izabal, Guatemala'],
        'issuedAt' => '21/10/2026 · 19:45:00', 'series' => 'DEMO2026', 'number' => '00000125', 'authorization' => 'DEMO2026-0000',
        'receiver' => ['name' => 'Comercial El Puerto — Ejemplo', 'nit' => 'CF', 'address' => 'Puerto Barrios'],
        'lines' => [['quantity' => 1, 'type' => 'Servicio', 'description' => 'Suscripción', 'unit_cents' => 25000, 'discount_cents' => 0, 'total_cents' => 25000]],
        'baseCents' => 22321, 'vatCents' => 2679, 'discountCents' => 0, 'totalCents' => 25000, 'totalInWords' => 'DOSCIENTOS CINCUENTA QUETZALES EXACTOS',
        'observation' => 'Servicios.', 'paymentMethod' => 'Transferencia bancaria.', 'certifier' => ['name' => 'Certificador de Ejemplo, S.A.', 'nit' => '0000000-0'],
        'certifiedAt' => '21/10/2026 · 19:45:03', 'qr' => 'data:image/svg+xml;base64,AAAA',
    ])->render();

    expect($html)->toContain('Q 223.21')->toContain('Q 26.79')->toContain('TOTAL A PAGAR')->toContain('Q 250.00')
        ->toContain('SIN VALIDEZ FISCAL')->toContain('DOSCIENTOS CINCUENTA QUETZALES EXACTOS');
});

test('el comando envía la vista previa por correo con el PDF adjunto', function () {
    Mail::fake();

    $this->artisan('fel:preview', ['email' => 'prueba@example.com'])->expectsOutputToContain('Vista previa enviada a prueba@example.com')->assertSuccessful();

    Mail::assertSent(FelPreviewMail::class, fn (FelPreviewMail $mail): bool => $mail->hasTo('prueba@example.com'));
});

test('el comando puede guardar el PDF y rechaza correos inválidos o sin destino', function () {
    Mail::fake();
    $path = tempnam(sys_get_temp_dir(), 'fel').'.pdf';

    $this->artisan('fel:preview', ['--save' => $path])->assertSuccessful();
    expect(substr((string) file_get_contents($path), 0, 4))->toBe('%PDF');
    unlink($path);

    $this->artisan('fel:preview', ['email' => 'no-es-correo'])->assertFailed();
    $this->artisan('fel:preview')->assertFailed();
    Mail::assertNothingSent();
});
