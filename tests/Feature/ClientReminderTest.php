<?php

use App\Mail\ClientReminderMail;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
});

/** @return array<string, mixed> */
function reminderFixture(): array
{
    $org = Organization::create(['name' => 'Firma Recordatorios', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'REC', 'name' => 'Oficina Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Quetzal', 'tax_id' => Str::upper(Str::random(8)),
        'currency' => 'GTQ', 'phone' => '5555-1234', 'created_at' => now(), 'updated_at' => now()]);
    $contact = function (string $role) use ($org, $office, $companyId): User {
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
        DB::table('user_role_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'user_id' => $user->id,
            'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $org->id)->where('code', 'client_user')->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('client_company_access')->insert(['organization_id' => $org->id, 'company_id' => $companyId, 'user_id' => $user->id, 'contact_role' => $role, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    };

    return ['org' => $org, 'office' => $office, 'owner' => $owner, 'companyId' => $companyId, 'client' => $contact('owner_contact'), 'viewer' => $contact('viewer')];
}

/** @param array<string, mixed> $f */
function reminderObligation(array $f, string $dueOn): int
{
    return DB::table('fiscal_obligations')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['companyId'],
        'generation_key' => 'iva-'.Str::random(6), 'obligation_type' => 'iva', 'name' => 'IVA mensual', 'reference_period' => 'octubre 2026', 'reference_year' => 2026, 'reference_month' => 10,
        'due_on' => $dueOn, 'internal_due_on' => $dueOn, 'review_due_on' => $dueOn, 'configuration_version' => 'v1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
}

test('an office reminds its clients days before their deadlines by panel, email and WhatsApp, once', function () {
    $f = reminderFixture();
    $this->travelTo(Carbon::parse('2026-11-02 08:30', 'America/Guatemala'));
    config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '123456']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    Mail::fake();

    $this->actingAs($f['owner'])->patch(route('office.offices.clientReminders', $f['office']->id), [
        'enabled' => true, 'days_before' => [1, 5],
        'channels' => ['in_app' => true, 'email' => true, 'whatsapp' => true],
        'kinds' => ['fee_invoices' => true, 'document_requests' => true, 'tax_obligations' => true],
    ])->assertSessionHasNoErrors();
    $this->actingAs($f['owner'])->get(route('office.offices.show', $f['office']->id))->assertInertia(fn (Assert $page) => $page
        ->where('admin.office.client_reminders.settings.enabled', true)->where('admin.office.client_reminders.settings.days_before', [5, 1])
        ->where('admin.office.client_reminders.whatsapp_available', true));

    $invoiceId = DB::table('crm_invoices')->insertGetId(['organization_id' => $f['org']->id, 'company_id' => $f['companyId'], 'number' => 'HON-101', 'concept' => 'Honorarios de noviembre',
        'amount_cents' => 150000, 'issued_on' => '2026-11-01', 'due_on' => '2026-11-07', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $obligationId = reminderObligation($f, '2026-11-03');
    // Not on a reminder day, or already paid: nothing is sent for them.
    reminderObligation($f, '2026-11-10');
    DB::table('crm_invoices')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['companyId'], 'number' => 'HON-099', 'concept' => 'Pagada',
        'amount_cents' => 1000, 'issued_on' => '2026-10-01', 'due_on' => '2026-11-07', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('clients:remind')->assertSuccessful();

    // The owner contact sees both; the viewer cannot see the office's invoices, so it only hears about the tax.
    expect(DB::table('work_notifications')->where('user_id', $f['client']->id)->where('type', 'client.reminder')->count())->toBe(2)
        ->and(DB::table('work_notifications')->where('user_id', $f['viewer']->id)->where('type', 'client.reminder')->pluck('related_id')->all())->toBe([$obligationId])
        ->and(DB::table('work_notifications')->where('user_id', $f['client']->id)->where('related_type', 'crm_invoices')->value('title'))->toContain('HON-101');
    Mail::assertSent(ClientReminderMail::class, 3);
    Mail::assertSent(ClientReminderMail::class, fn (ClientReminderMail $mail): bool => $mail->hasTo($f['client']->email) && str_contains($mail->body, 'vence en 5 días'));
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['to'] === '50255551234' && $request['template']['name'] === 'recordatorio_vencimiento');
    expect(DB::table('client_reminder_deliveries')->where('source_type', 'crm_invoice')->where('source_id', $invoiceId)->where('days_before', 5)->count())->toBe(3);

    // Running again the same day repeats nothing.
    $this->artisan('clients:remind')->assertSuccessful();
    expect(DB::table('work_notifications')->where('type', 'client.reminder')->count())->toBe(3);
    Mail::assertSent(ClientReminderMail::class, 3);
    Http::assertSentCount(2);
});

test('reminders stay off until the office turns them on, and need a channel', function () {
    $f = reminderFixture();
    $this->travelTo(Carbon::parse('2026-11-02 08:30', 'America/Guatemala'));
    Mail::fake();
    reminderObligation($f, '2026-11-07');

    $this->artisan('clients:remind')->assertSuccessful();
    expect(DB::table('work_notifications')->where('type', 'client.reminder')->count())->toBe(0);

    $this->actingAs($f['owner'])->patch(route('office.offices.clientReminders', $f['office']->id), [
        'enabled' => true, 'days_before' => [5],
        'channels' => ['in_app' => false, 'email' => false, 'whatsapp' => false],
        'kinds' => ['fee_invoices' => true, 'document_requests' => true, 'tax_obligations' => true],
    ])->assertSessionHasErrors('channels');
    $this->actingAs($f['owner'])->patch(route('office.offices.clientReminders', $f['office']->id), [
        'enabled' => true, 'days_before' => [90],
        'channels' => ['in_app' => true, 'email' => false, 'whatsapp' => false],
        'kinds' => ['fee_invoices' => true, 'document_requests' => true, 'tax_obligations' => true],
    ])->assertSessionHasErrors('days_before.0');

    // WhatsApp chosen but not connected on the platform: the other channels still go out.
    $this->actingAs($f['owner'])->patch(route('office.offices.clientReminders', $f['office']->id), [
        'enabled' => true, 'days_before' => [5],
        'channels' => ['in_app' => true, 'email' => false, 'whatsapp' => true],
        'kinds' => ['fee_invoices' => false, 'document_requests' => false, 'tax_obligations' => true],
    ])->assertSessionHasNoErrors();
    Http::fake();
    $this->artisan('clients:remind')->assertSuccessful();
    expect(DB::table('work_notifications')->where('type', 'client.reminder')->count())->toBe(2);
    Http::assertNothingSent();
    Mail::assertNothingSent();

    $this->actingAs($f['viewer'])->patch(route('office.offices.clientReminders', $f['office']->id), [
        'enabled' => false, 'days_before' => [5], 'channels' => ['in_app' => true, 'email' => false, 'whatsapp' => false],
        'kinds' => ['fee_invoices' => true, 'document_requests' => true, 'tax_obligations' => true],
    ])->assertForbidden();
});
