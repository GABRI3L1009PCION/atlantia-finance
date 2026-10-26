<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a finished or skipped tour is remembered and shared with every page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('profile.edit'))
        ->post(route('product-tours.update', 'firm'), ['status' => 'completed'])
        ->assertRedirect(route('profile.edit'));
    $this->actingAs($user)->post(route('product-tours.update', 'company'), ['status' => 'skipped'])->assertSessionHasNoErrors();

    expect($user->refresh()->product_tours)->toHaveKeys(['firm', 'company'])
        ->and($user->product_tours['firm']['status'])->toBe('completed')
        ->and($user->product_tours['company']['status'])->toBe('skipped');

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('productTours', ['firm', 'company']));
});

test('a new user has no tour seen yet', function () {
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('productTours', []));
});

test('only known tours and statuses are accepted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/product-tours/unknown', ['status' => 'completed'])->assertClientError();
    $this->actingAs($user)->post(route('product-tours.update', 'firm'), ['status' => 'maybe'])->assertSessionHasErrors('status');

    expect($user->refresh()->product_tours)->toBeNull();
});
