<?php

test('con HTTPS obligatorio una petición HTTP se redirige a la misma dirección segura', function () {
    config(['app.force_https' => true]);

    $this->get('http://atlantia.test/login?plan=professional')->assertStatus(301)->assertRedirect('https://atlantia.test/login?plan=professional');
    $this->post('http://atlantia.test/login')->assertStatus(301);
});

test('la revisión de salud del balanceador no se redirige', function () {
    config(['app.force_https' => true]);

    $this->get('http://atlantia.test/up')->assertOk();
});

test('una petición que ya llegó por HTTPS no se redirige y recibe HSTS', function () {
    config(['app.force_https' => true]);

    $this->get('https://atlantia.test/login')->assertOk()->assertHeader('Strict-Transport-Security');
});

test('detrás de un balanceador de confianza el aviso de HTTPS evita el redireccionamiento', function () {
    config(['app.force_https' => true, 'trustedproxy.proxies' => '*']);

    $this->get('http://atlantia.test/login', ['X-Forwarded-Proto' => 'https'])->assertOk()->assertHeader('Strict-Transport-Security');
});

test('sin balanceador de confianza el aviso de HTTPS de un visitante no se cree', function () {
    config(['app.force_https' => true, 'trustedproxy.proxies' => null]);

    $this->get('http://atlantia.test/login', ['X-Forwarded-Proto' => 'https'])->assertStatus(301);
});

test('sin HTTPS obligatorio (desarrollo local) no se redirige', function () {
    config(['app.force_https' => false]);

    $this->get('http://atlantia.test/login')->assertOk();
});
