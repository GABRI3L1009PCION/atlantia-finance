<?php

use App\Models\Organization;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationCodeService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
    Cache::flush();
});

/** Sends the code the way the app does and returns the one that went out. */
function codeSentTo(User $user): string
{
    $sent = null;
    Notification::assertNothingSent();
    $user->sendEmailVerificationNotification();
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use (&$sent): bool {
        $sent = $notification->code;

        return true;
    });

    return (string) $sent;
}

test('el código llega por correo con 6 dígitos y el asunto en español', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $code = codeSentTo($user);

    expect($code)->toMatch('/^\d{6}$/');
    $mail = (new VerifyEmailNotification($code))->toMail($user);
    expect($mail->subject)->toBe('Tu código de verificación | Atlantia Finance')
        ->and(view($mail->view, $mail->viewData)->render())->toContain($code);
});

test('el código correcto verifica el correo y el dueño sigue al pago', function () {
    Notification::fake();
    $this->seed(PlanCatalogSeeder::class);
    $user = User::factory()->unverified()->create();
    $organization = Organization::query()->create(['name' => 'Despacho Código', 'status' => 'pending_email_verification']);
    $organization->users()->attach($user->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $code = codeSentTo($user);
    Event::fake([Verified::class]);

    $this->actingAs($user)->post(route('verification.code'), ['code' => $code])->assertRedirect(route('billing.checkout.show'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()->and($organization->fresh()->status)->toBe('pending_payment');
    Event::assertDispatched(Verified::class);
    $this->post(route('verification.code'), ['code' => $code])->assertRedirect(route('dashboard'));
});

test('un código incorrecto no verifica y a los 5 intentos hay que pedir uno nuevo', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $code = codeSentTo($user);
    $wrong = $code === '123456' ? '654321' : '123456';

    foreach (range(1, 4) as $attempt) {
        $this->actingAs($user)->post(route('verification.code'), ['code' => $wrong])->assertSessionHasErrors(['code' => 'El código no es correcto. Revisa el correo e inténtalo de nuevo.']);
    }
    $this->post(route('verification.code'), ['code' => $wrong])->assertSessionHasErrors('code');
    // The right code no longer works once the attempts are used up.
    $this->post(route('verification.code'), ['code' => $code])->assertSessionHasErrors('code');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('el código vence a los 15 minutos', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $code = codeSentTo($user);

    $this->travel(EmailVerificationCodeService::VALID_MINUTES + 1)->minutes();

    $this->actingAs($user)->post(route('verification.code'), ['code' => $code])->assertSessionHasErrors('code');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('el código solo sirve para su dueño y se pide con 6 dígitos', function () {
    Notification::fake();
    $owner = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $code = codeSentTo($owner);

    $this->actingAs($other)->post(route('verification.code'), ['code' => $code])->assertSessionHasErrors('code');
    $this->post(route('verification.code'), ['code' => '12ab'])->assertSessionHasErrors('code');
    $this->post(route('verification.code'), [])->assertSessionHasErrors('code');

    expect($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('reenviar tiene una espera corta y el código nuevo reemplaza al anterior', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $first = codeSentTo($user);

    expect($user->fresh()->sendEmailVerificationNotification())->toBeNull();
    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    expect(app(EmailVerificationCodeService::class)->resendIn($user))->toBeGreaterThan(0);

    $this->travel(EmailVerificationCodeService::RESEND_SECONDS + 1)->seconds();
    $user->sendEmailVerificationNotification();
    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2);

    $latest = null;
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use (&$latest): bool {
        $latest = $notification->code;

        return true;
    });
    if ($latest !== $first) {
        $this->actingAs($user)->post(route('verification.code'), ['code' => $first])->assertSessionHasErrors('code');
    }
});

test('el registro envía el código y la pantalla de verificación lo pide', function () {
    Notification::fake();
    $this->seed(PlanCatalogSeeder::class);

    $this->post(route('register.store'), ['name' => 'Persona Nueva', 'organization_name' => 'Despacho Nuevo', 'email' => 'nuevo@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'plan' => 'professional', 'billing_period' => 'monthly', 'terms' => true])->assertRedirect(route('verification.notice', absolute: false));

    $user = User::where('email', 'nuevo@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmailNotification::class, fn (VerifyEmailNotification $notification): bool => preg_match('/^\d{6}$/', $notification->code) === 1);
    $this->get(route('verification.notice'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/verify-email')->where('codeMinutes', 15)->where('resendIn', fn ($seconds) => $seconds > 0));
});
