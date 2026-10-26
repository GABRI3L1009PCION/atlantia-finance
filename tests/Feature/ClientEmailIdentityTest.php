<?php

use App\Mail\ClientReplyVerificationMail;
use App\Mail\CollectionReminderMail;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Notifications\ClientMailIdentityService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('the firm only types its address and confirms it with the link it receives', function () {
    Mail::fake();
    [$organization, $owner] = clientEmailFirm();

    $this->actingAs($owner)->post(route('office.clientEmail.store'), ['email' => 'Cobros@HermanosDemo.test'])->assertSessionHasNoErrors();
    $url = null;
    Mail::assertSent(ClientReplyVerificationMail::class, function (ClientReplyVerificationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return $mail->hasTo('cobros@hermanosdemo.test') && $mail->firm === 'Hermanos Demo';
    });
    expect(app(ClientMailIdentityService::class)->status($organization->id))->toMatchArray(['email' => 'cobros@hermanosdemo.test', 'verified' => false]);
    $this->get(route('office.admin.settings'))->assertInertia(fn (Assert $page) => $page
        ->where('clientEmail.email', 'cobros@hermanosdemo.test')->where('clientEmail.verified', false)->where('clientEmail.canManage', true));

    // The link works without a session, and a tampered one does nothing.
    auth()->logout();
    $this->get($url.'x')->assertForbidden();
    $this->get($url)->assertOk()->assertSee('Correo confirmado');
    expect(app(ClientMailIdentityService::class)->identity($organization->id))->toMatchArray(['reply_to' => 'cobros@hermanosdemo.test', 'from_name' => 'Hermanos Demo', 'reply_verified' => true]);

    $this->actingAs($owner)->post(route('office.clientEmail.test'))->assertSessionHasNoErrors();
    Mail::assertSent(CollectionReminderMail::class, fn (CollectionReminderMail $mail) => $mail->hasTo('cobros@hermanosdemo.test'));
});

test('a link sent for an address that was changed afterwards no longer confirms anything', function () {
    Mail::fake();
    [$organization, $owner] = clientEmailFirm();
    $this->actingAs($owner)->post(route('office.clientEmail.store'), ['email' => 'viejo@hermanosdemo.test']);
    $old = null;
    Mail::assertSent(ClientReplyVerificationMail::class, function (ClientReplyVerificationMail $mail) use (&$old): bool {
        $old ??= $mail->url;

        return true;
    });
    $this->post(route('office.clientEmail.store'), ['email' => 'nuevo@hermanosdemo.test']);

    $this->get($old)->assertOk()->assertSee('Enlace no válido');
    expect(app(ClientMailIdentityService::class)->status($organization->id)['verified'])->toBeFalse();

    $this->delete(route('office.clientEmail.destroy'))->assertSessionHasNoErrors();
    expect(app(ClientMailIdentityService::class)->status($organization->id)['email'])->toBeNull();
});

test('only who manages the firm settings can change the address, and a display name cannot break the header', function () {
    [$organization] = clientEmailFirm();
    $member = User::factory()->create();
    $organization->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);

    $this->actingAs($member)->post(route('office.clientEmail.store'), ['email' => 'otro@demo.test'])->assertForbidden();
    expect(ClientMailIdentityService::displayName("Empresa \"Uno\"\r\nBcc: x@y.test <z>"))->not->toContain("\n")->not->toContain('"')->not->toContain('<');
});

/** @return array{0: Organization, 1: User} */
function clientEmailFirm(): array
{
    $organization = Organization::create(['name' => 'Hermanos Demo', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $organization->offices()->create(['code' => 'MAIN', 'name' => 'Hermanos Demo', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($organization);
    DB::table('firm_profiles')->insert(['organization_id' => $organization->id, 'trade_name' => 'Hermanos Demo', 'created_at' => now(), 'updated_at' => now()]);

    return [$organization, $owner];
}
