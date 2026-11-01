<?php

test('the layout links the Atlantia Finance icons instead of the Laravel ones', function () {
    config(['service_hours.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertSee('href="/favicon.ico?v=2"', false)
        ->assertSee('href="/favicon.png?v=2"', false)
        ->assertSee('href="/apple-touch-icon.png?v=2"', false)
        ->assertDontSee('favicon.svg', false);
});

test('every linked icon exists in the public directory', function (string $file) {
    expect(public_path($file))->toBeFile();
})->with(['favicon.ico', 'favicon.png', 'apple-touch-icon.png']);
