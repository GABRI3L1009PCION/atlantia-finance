<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(9, 0));
    $this->withoutVite();
});

test('the desktop app registers the computer and downloads only the companies the person can reach', function () {
    $f = desktopFirm();
    $device = (string) Str::uuid();

    $this->actingAs($f['assistant'])->postJson(route('desktop.devices'), ['device_uuid' => $device, 'name' => 'Laptop de Ana', 'platform' => 'windows', 'app_version' => '1.0.0'])
        ->assertOk()->assertJson(['max_offline_days' => 15]);
    $snapshot = $this->getJson(route('desktop.snapshot', ['device_uuid' => $device]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

    expect(collect($snapshot['companies'])->pluck('name')->all())->toBe(['El Progreso'])
        ->and($snapshot['companies'][0]['customers'][0]['name'])->toBe('Finca Las Margaritas')
        ->and(collect($snapshot['companies'][0]['accounts'])->pluck('code'))->toContain('5201')
        ->and($snapshot['companies'][0]['periods'][0]['name'])->toBe('Octubre 2026');
    expect(DB::table('desktop_devices')->where('device_uuid', $device)->value('last_snapshot_at'))->not->toBeNull();

    // A revoked computer is told to erase its copy.
    DB::table('desktop_devices')->where('device_uuid', $device)->update(['revoked_at' => now()]);
    $this->getJson(route('desktop.snapshot', ['device_uuid' => $device]))->assertStatus(410);
    $this->postJson(route('desktop.devices'), ['device_uuid' => $device, 'name' => 'Laptop de Ana', 'platform' => 'windows'])->assertStatus(410);
});

test('captures made without connection become drafts once, and the ones that cannot are kept with the reason', function () {
    $f = desktopFirm();
    $device = (string) Str::uuid();
    $this->actingAs($f['owner'])->postJson(route('desktop.devices'), ['device_uuid' => $device, 'name' => 'PC oficina', 'platform' => 'mac'])->assertOk();
    $company = (string) DB::table('companies')->where('id', $f['company'])->value('public_id');
    $accounts = DB::table('ledger_accounts')->where('company_id', $f['company'])->pluck('id', 'code');
    $captures = [
        ['id' => $sale = (string) Str::uuid(), 'company' => $company, 'kind' => 'sale', 'captured_at' => '2026-10-27T08:15:00-06:00',
            'payload' => ['customer_id' => $f['customer'], 'date' => '2026-10-27', 'lines' => [['description' => 'Fertilizante 20 sacos', 'quantity' => 1, 'unit_price' => '5600.00', 'tax_treatment' => 'goods']]]],
        ['id' => $purchase = (string) Str::uuid(), 'company' => $company, 'kind' => 'purchase', 'captured_at' => '2026-10-27T08:20:00-06:00',
            'payload' => ['supplier_id' => $f['supplier'], 'document_type' => 'expense', 'reference' => 'FAC-881', 'date' => '2026-10-27', 'due_on' => '2026-11-26',
                'lines' => [['description' => 'Transporte', 'quantity' => 1, 'unit_price' => '1680.00', 'tax_treatment' => 'services', 'account_id' => $accounts['5201']]]]],
        ['id' => $journal = (string) Str::uuid(), 'company' => $company, 'kind' => 'journal', 'captured_at' => '2026-10-27T08:30:00-06:00',
            'payload' => ['date' => '2026-10-27', 'description' => 'Pago de luz', 'lines' => [
                ['account_id' => $accounts['5201'], 'debit' => '450.00', 'credit' => '0'], ['account_id' => $accounts['1102'], 'debit' => '0', 'credit' => '450.00']]]],
        ['id' => $late = (string) Str::uuid(), 'company' => $company, 'kind' => 'journal', 'captured_at' => '2026-10-27T08:40:00-06:00',
            'payload' => ['date' => '2027-03-01', 'description' => 'Sin período', 'lines' => [
                ['account_id' => $accounts['5201'], 'debit' => '10.00', 'credit' => '0'], ['account_id' => $accounts['1102'], 'debit' => '0', 'credit' => '10.00']]]],
    ];

    $results = collect($this->postJson(route('desktop.captures'), ['device_uuid' => $device, 'captures' => $captures])->assertOk()->json('results'))->keyBy('id');

    expect($results[$sale]['status'])->toBe('draft_created')->and($results[$sale]['url'])->toEndWith('/sales/invoices/'.$sale)
        ->and($results[$purchase]['status'])->toBe('draft_created')
        ->and($results[$journal]['status'])->toBe('draft_created')
        ->and($results[$late]['status'])->toBe('failed')->and($results[$late]['message'])->toContain('No hay un período contable abierto');
    expect(DB::table('sales_invoices')->where('public_id', $sale)->value('status'))->toBe('draft')
        ->and(DB::table('purchase_documents')->where('public_id', $purchase)->exists())->toBeTrue()
        ->and(DB::table('journal_entries')->where('public_id', $journal)->value('status'))->toBe('draft');

    // The same upload again (a dropped connection) changes nothing.
    $this->postJson(route('desktop.captures'), ['device_uuid' => $device, 'captures' => $captures])->assertOk();
    expect(DB::table('offline_captures')->count())->toBe(4)->and(DB::table('sales_invoices')->where('public_id', $sale)->count())->toBe(1);
});

test('a person cannot upload into a company they do not reach, nor claim another person\'s capture', function () {
    $f = desktopFirm();
    $device = (string) Str::uuid();
    $this->actingAs($f['owner'])->postJson(route('desktop.devices'), ['device_uuid' => $device, 'name' => 'PC', 'platform' => 'windows']);
    $other = (string) DB::table('companies')->where('id', $f['other'])->value('public_id');
    $id = (string) Str::uuid();
    $capture = ['id' => $id, 'company' => (string) DB::table('companies')->where('id', $f['company'])->value('public_id'), 'kind' => 'journal', 'captured_at' => now()->toIso8601String(),
        'payload' => ['date' => '2027-01-01', 'lines' => []]];
    $this->postJson(route('desktop.captures'), ['device_uuid' => $device, 'captures' => [$capture]])->assertOk();

    $assistantDevice = (string) Str::uuid();
    $this->actingAs($f['assistant'])->postJson(route('desktop.devices'), ['device_uuid' => $assistantDevice, 'name' => 'Laptop', 'platform' => 'windows']);
    $results = $this->postJson(route('desktop.captures'), ['device_uuid' => $assistantDevice, 'captures' => [
        ['id' => (string) Str::uuid(), 'company' => $other, 'kind' => 'journal', 'captured_at' => now()->toIso8601String(), 'payload' => ['date' => '2026-10-27']],
        [...$capture, 'company' => $capture['company']],
    ]])->assertOk()->json('results');

    expect($results[0]['message'])->toBe('La empresa ya no está disponible para ti.')
        ->and($results[1]['message'])->toBe('Captura no válida.');
    $this->postJson(route('desktop.captures'), ['device_uuid' => $device, 'captures' => [$capture]])->assertNotFound();
});

test('the desktop page lists the person\'s computers and captures; the owner disconnects any computer of the firm', function () {
    $f = desktopFirm();
    $device = (string) Str::uuid();
    $this->actingAs($f['assistant'])->postJson(route('desktop.devices'), ['device_uuid' => $device, 'name' => 'Laptop de Ana', 'platform' => 'windows']);
    $company = (string) DB::table('companies')->where('id', $f['company'])->value('public_id');
    $this->postJson(route('desktop.captures'), ['device_uuid' => $device, 'captures' => [
        ['id' => (string) Str::uuid(), 'company' => $company, 'kind' => 'journal', 'captured_at' => now()->toIso8601String(), 'payload' => ['date' => '2027-02-01', 'description' => 'Sin período', 'lines' => []]],
    ]]);

    $this->get(route('desktop.index'))->assertOk()->assertInertia(fn ($page) => $page->component('desktop/index')
        ->where('devices.0.name', 'Laptop de Ana')->where('canManageFirm', false)
        ->where('captures.0.status', 'failed')->where('captures.0.kind_label', 'Asiento contable'));
    $deviceId = (int) DB::table('desktop_devices')->where('device_uuid', $device)->value('id');
    $captureId = (int) DB::table('offline_captures')->value('id');

    $this->actingAs($f['owner'])->get(route('desktop.index'))->assertInertia(fn ($page) => $page->where('canManageFirm', true)->where('devices.0.user', $f['assistant']->name));
    $this->post(route('desktop.devices.revoke', $deviceId))->assertSessionHasNoErrors();
    $this->post(route('desktop.captures.dismiss', $captureId))->assertSessionHasNoErrors();

    expect(DB::table('desktop_devices')->where('id', $deviceId)->value('revoked_at'))->not->toBeNull()
        ->and(DB::table('offline_captures')->where('id', $captureId)->value('resolved_at'))->not->toBeNull();
    $this->actingAs($f['assistant'])->getJson(route('desktop.snapshot', ['device_uuid' => $device]))->assertStatus(410);
});

/** @return array{org: Organization, owner: User, assistant: User, company: int, other: int, customer: int, supplier: int} */
function desktopFirm(): array
{
    $org = Organization::create(['name' => 'Hermanos Demo', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Hermanos Demo', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $office->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $assistant = User::factory()->create();
    $org->users()->attach($assistant->id, ['role' => 'member', 'status' => 'active']);
    $office->users()->attach($assistant->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'user_id' => $assistant->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $org->id)->where('code', 'accounting_assistant')->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $company = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'El Progreso', 'tax_id' => '8754321-4', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $other = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => '4567890-1', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_user')->insert(['company_id' => $company, 'user_id' => $assistant->id, 'permission' => 'editor']);
    foreach ([$company, $other] as $id) {
        DB::table('company_fiscal_profiles')->insert(['company_id' => $id, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingAutomationService::class)->ensureMapping($id);
        DB::table('accounting_periods')->insert(['company_id' => $id, 'name' => 'Octubre 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $company, 'name' => 'Finca Las Margaritas', 'tax_id' => '3216549-8', 'payment_terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $supplier = DB::table('purchase_suppliers')->insertGetId(['company_id' => $company, 'name' => 'Transportes Rápidos del Sur', 'tax_id' => '2468135-0', 'created_at' => now(), 'updated_at' => now()]);

    return compact('org', 'owner', 'assistant', 'company', 'other', 'customer', 'supplier');
}
