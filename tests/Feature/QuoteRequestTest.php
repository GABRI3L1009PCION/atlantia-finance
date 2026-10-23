<?php

use App\Mail\QuoteRequestMail;
use Illuminate\Support\Facades\Mail;

test('la solicitud de cotización llega al correo del equipo y responder contesta a la firma', function () {
    Mail::fake();
    config()->set('atlantia-plans.quote_email', 'soporte@atlantiasystems.com');

    $this->post(route('quote.store'), ['name' => 'Ana Pérez', 'firm' => 'Firma Grande, S.A.', 'email' => 'ana@firma.test', 'phone' => '5555-1234', 'offices' => '12', 'message' => 'Necesitamos migrar datos.'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(QuoteRequestMail::class, fn (QuoteRequestMail $mail): bool => $mail->hasTo('soporte@atlantiasystems.com')
        && $mail->hasReplyTo('ana@firma.test')
        && $mail->request['firm'] === 'Firma Grande, S.A.');
});

test('la solicitud de cotización pide los datos básicos y descarta el relleno de robots', function () {
    Mail::fake();

    $this->post(route('quote.store'), ['name' => '', 'firm' => '', 'email' => 'no-es-correo'])->assertSessionHasErrors(['name', 'firm', 'email']);
    $this->post(route('quote.store'), ['name' => 'Bot Automático', 'firm' => 'Spam', 'email' => 'bot@spam.test', 'website' => 'http://spam.test'])->assertSessionHasErrors('website');

    Mail::assertNothingSent();
});

test('la página de cotización es pública', function () {
    $this->withoutVite();

    $this->get(route('quote.create'))->assertOk();
});
