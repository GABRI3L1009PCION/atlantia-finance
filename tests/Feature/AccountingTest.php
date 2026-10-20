<?php

use App\Actions\Accounting\ApproveAccountingPeriodReopening;
use App\Actions\Accounting\ReopenAccountingPeriod;
use App\Actions\Accounting\RequestAccountingPeriodReopening;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

test('owner installs the professional starter chart without creating duplicates', function () {
    $f = accountingFixture();

    $this->actingAs($f['owner'])
        ->post(route('accounting.accountTemplate', $f['uuid']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('ledger_accounts')->where('company_id', $f['companyId'])->count())->toBe(27);
    $this->assertDatabaseHas('ledger_accounts', [
        'company_id' => $f['companyId'],
        'code' => '2102',
        'name' => 'Cuentas por pagar',
        'type' => 'liability',
    ]);

    $this->post(route('accounting.accountTemplate', $f['uuid']))->assertSessionHasNoErrors();
    expect(DB::table('ledger_accounts')->where('company_id', $f['companyId'])->count())->toBe(27);
});

test('draft entries keep references and secure support documents that become immutable after posting', function () {
    Storage::fake('local');
    $f = accountingFixture();
    $data = [...accountingDraft($f), 'kind' => 'adjustment', 'reference' => 'AJ-2026-001'];

    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data)->assertRedirect();
    $this->assertDatabaseHas('journal_entries', [
        'public_id' => $data['request_id'],
        'kind' => 'adjustment',
        'reference' => 'AJ-2026-001',
    ]);

    $upload = $this->post(route('accounting.entryDocumentStore', [$f['uuid'], $data['request_id']]), [
        'document' => UploadedFile::fake()->create('respaldo.pdf', 120, 'application/pdf'),
    ]);
    $upload->assertRedirect()->assertSessionHasNoErrors();
    $document = DB::table('journal_entry_documents')->firstOrFail();
    Storage::disk('local')->assertExists($document->storage_path);

    $this->get(route('accounting.entryDocumentDownload', [$f['uuid'], $data['request_id'], $document->public_id]))
        ->assertOk()
        ->assertHeader('cache-control', 'no-store, private');

    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post'])->assertRedirect();
    $this->delete(route('accounting.entryDocumentDestroy', [$f['uuid'], $data['request_id'], $document->public_id]))
        ->assertSessionHasErrors('document');
    Storage::disk('local')->assertExists($document->storage_path);
});

test('owner corrects a used account with complete audit without changing posted entries', function () {
    $f = accountingFixture();
    DB::table('ledger_accounts')->where('id', $f['account2'])->update(['type' => 'asset']);
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data);
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post']);
    $lines = DB::table('journal_lines')->get()->toJson();
    $entries = DB::table('journal_entries')->get()->toJson();
    $this->patch(route('accounting.updateAccount', [$f['uuid'], $f['account2']]), [
        'code' => '3101', 'name' => 'Capital aportado', 'type' => 'equity', 'reason' => 'Corregir clasificación inicial', 'acknowledged' => true,
        'company_id' => 999,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('ledger_accounts', ['id' => $f['account2'], 'company_id' => $f['companyId'], 'type' => 'equity', 'name' => 'Capital aportado']);
    expect(DB::table('journal_lines')->get()->toJson())->toBe($lines);
    expect(DB::table('journal_entries')->get()->toJson())->toBe($entries);
    $log = DB::table('audit_logs')->where('action', 'account.updated')->first();
    expect(json_decode($log->old_values, true)['type'])->toBe('asset');
    expect(json_decode($log->new_values, true)['reason'])->toBe('Corregir clasificación inicial');
    expect((int) $log->user_id)->toBe($f['owner']->id);
    $this->get(route('accounting.show', $f['uuid']))->assertInertia(fn (Assert $p) => $p->has('accountHistory', 1)->where('accountHistory.0.before.type', 'asset')->where('accountHistory.0.after.type', 'equity')->where('balances.1.type', 'equity'));
});

test('account corrections reject unauthorized foreign duplicate and unacknowledged requests', function () {
    $f = accountingFixture();
    $other = accountingFixture();
    $payload = ['code' => '3101', 'name' => 'Capital corregido', 'type' => 'equity', 'reason' => 'Corrección', 'acknowledged' => true];
    $url = route('accounting.updateAccount', [$f['uuid'], $f['account2']]);
    $this->actingAs($other['owner'])->patch($url, $payload)->assertNotFound();
    $this->actingAs($f['owner'])->patch(route('accounting.updateAccount', [$f['uuid'], $other['account2']]), $payload)->assertNotFound();
    $this->patch($url, [...$payload, 'code' => '1101'])->assertSessionHasErrors('code');
    $this->patch($url, [...$payload, 'type' => 'forged'])->assertSessionHasErrors('type');
    $this->patch($url, [...$payload, 'reason' => ''])->assertSessionHasErrors('reason');
    $this->patch($url, [...$payload, 'acknowledged' => false])->assertSessionHasErrors('acknowledged');
    $member = User::factory()->create();
    $f['org']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($member->id, ['status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $f['companyId'], 'user_id' => $member->id, 'permission' => 'editor']);
    $this->actingAs($member)->patch($url, $payload)->assertForbidden();
    $this->get(route('accounting.show', $f['uuid']))->assertInertia(fn (Assert $p) => $p->has('accountHistory', 0));
    expect(DB::table('audit_logs')->where('action', 'account.updated')->count())->toBe(0);
});

test('closed posted periods freeze account metadata and do not produce correction audit', function () {
    $f = accountingFixture();
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data);
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post']);
    $entryId = DB::table('journal_entries')->where('public_id', $data['request_id'])->value('id');
    DB::table('journal_entry_documents')->insert([
        'public_id' => (string) Str::uuid(),
        'journal_entry_id' => $entryId,
        'uploaded_by' => $f['owner']->id,
        'original_name' => 'soporte.pdf',
        'storage_path' => 'testing/soporte-'.$entryId.'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 128,
        'sha256' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertRedirect();
    $this->assertDatabaseHas('accounting_period_close_snapshots', [
        'company_id' => $f['companyId'],
        'accounting_period_id' => $f['period'],
        'closed_by' => $f['owner']->id,
    ]);
    $this->patch(route('accounting.updateAccount', [$f['uuid'], $f['account2']]), ['code' => '3101', 'name' => 'Cambio tardío', 'type' => 'equity', 'reason' => 'Corrección', 'acknowledged' => true])->assertSessionHasErrors('accounting');
    $this->assertDatabaseHas('ledger_accounts', ['id' => $f['account2'], 'name' => 'Capital']);
    expect(DB::table('audit_logs')->where('action', 'account.updated')->count())->toBe(0);
});

function accountingFixture(): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Oficina contable', 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $org->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede principal', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Cliente de prueba', 'tax_id' => '123-K', 'currency' => 'GTQ']);
    $account1 = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '1101', 'name' => 'Caja', 'type' => 'asset']);
    $account2 = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '3101', 'name' => 'Capital', 'type' => 'equity']);
    $period = DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => 'Agosto 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);

    return compact('owner', 'org', 'office', 'uuid', 'companyId', 'account1', 'account2', 'period');
}

function accountingDraft(array $f): array
{
    return ['request_id' => (string) Str::uuid(), 'period_id' => $f['period'], 'entry_date' => '2026-08-01', 'description' => 'Aporte inicial', 'kind' => 'opening', 'lines' => [['account_id' => $f['account1'], 'debit' => '1000.10', 'credit' => '0'], ['account_id' => $f['account2'], 'debit' => '0', 'credit' => '1000.10']]];
}

test('owner registers scoped company accounts periods and actual company view', function () {
    $f = accountingFixture();
    $this->actingAs($f['owner'])->post(route('accounting.storeCompany'), ['name' => 'Nueva empresa', 'tax_id' => '987-k', 'office_id' => $f['office']->id, 'currency' => 'USD'])->assertRedirect();
    $this->assertDatabaseHas('companies', ['name' => 'Nueva empresa', 'tax_id' => '987-K', 'currency' => 'GTQ']);
    $this->post(route('accounting.account', $f['uuid']), ['code' => '5101', 'name' => 'Gastos', 'type' => 'expense'])->assertRedirect();
    $this->post(route('accounting.account', $f['uuid']), ['code' => '5101', 'name' => 'Duplicada', 'type' => 'expense'])->assertSessionHasErrors('code');
    $this->post(route('accounting.period', $f['uuid']), ['name' => 'Septiembre', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertRedirect();
    $this->get(route('accounting.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('accounting/index')->has('companies.data', 2));
    $this->get(route('accounting.show', $f['uuid']))->assertOk()->assertInertia(fn (Assert $p) => $p->component('accounting/company')->has('accounts', 3)->has('periods', 2)->where('canManage', true));
});

test('company quota and duplicate tax id are enforced without creating records', function () {
    $f = accountingFixture();
    $this->actingAs($f['owner'])->post(route('accounting.storeCompany'), ['name' => 'Duplicada', 'tax_id' => '123-k', 'office_id' => $f['office']->id])->assertSessionHasErrors('tax_id');
    DB::statement("UPDATE plan_versions SET limits = jsonb_set(limits, '{active_cases,value}', '1')");
    $this->post(route('accounting.storeCompany'), ['name' => 'Otra', 'tax_id' => '444', 'office_id' => $f['office']->id])->assertSessionHasErrors('tax_id');
    $this->assertDatabaseCount('companies', 1);
});

test('tenant isolation covers companies writes and report filters', function () {
    $f = accountingFixture();
    $other = accountingFixture();
    $this->actingAs($other['owner'])->get(route('accounting.show', $f['uuid']))->assertNotFound();
    $this->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertNotFound();
    $this->post(route('accounting.storeCompany'), ['name' => 'Ataque', 'tax_id' => '999', 'office_id' => $f['office']->id])->assertNotFound();
    $this->get(route('accounting.show', ['company' => $other['uuid'], 'period' => $f['period']]))->assertNotFound();
    $this->get(route('accounting.show', ['company' => $other['uuid'], 'account' => $f['account1']]))->assertNotFound();
    $this->get(route('accounting.index'))->assertInertia(fn (Assert $p) => $p->has('companies.data', 1)->where('companies.data.0.public_id', $other['uuid']));
});

test('unassigned viewer editor and inactive memberships have distinct permissions', function () {
    $f = accountingFixture();
    $member = User::factory()->create();
    $f['org']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $this->actingAs($member)->get(route('accounting.show', $f['uuid']))->assertNotFound();
    $this->actingAs($f['owner'])->post(route('accounting.assign', $f['uuid']), ['user_id' => $member->id, 'permission' => 'viewer'])->assertRedirect();
    $this->actingAs($member)->get(route('accounting.show', $f['uuid']))->assertInertia(fn (Assert $p) => $p->where('canWrite', false)->where('canManage', false)->has('members', 0));
    $this->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertForbidden();
    $this->actingAs($f['owner'])->post(route('accounting.assign', $f['uuid']), ['user_id' => $member->id, 'permission' => 'editor']);
    $data = accountingDraft($f);
    $this->actingAs($member)->post(route('accounting.entry', $f['uuid']), [...$data, 'status' => 'posted', 'posted_by' => $member->id])->assertRedirect();
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'draft', 'posted_by' => null]);
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post'])->assertForbidden();
    $this->post(route('accounting.account', $f['uuid']), ['code' => '9', 'name' => 'No', 'type' => 'asset'])->assertForbidden();
    $this->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertForbidden();
    $f['office']->users()->updateExistingPivot($member->id, ['status' => 'inactive']);
    $this->get(route('accounting.show', $f['uuid']))->assertNotFound();
    $f['org']->users()->updateExistingPivot($member->id, ['status' => 'inactive']);
    $this->get(route('accounting.index'))->assertForbidden();
});

test('auditor access is strictly read only even with stale editor access', function () {
    $f = accountingFixture();
    $auditor = User::factory()->create();
    $f['org']->users()->attach($auditor->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($auditor->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $f['companyId'], 'user_id' => $auditor->id, 'permission' => 'editor']);
    DB::table('company_assignments')->insert([
        'organization_id' => $f['org']->id,
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'user_id' => $auditor->id,
        'assignment_role' => 'auditor',
        'status' => 'active',
        'starts_at' => now(),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($auditor)
        ->get(route('accounting.show', $f['uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canWrite', false)
            ->where('journalPermissions.create', false)
            ->where('journalPermissions.post', false));

    $this->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertForbidden();
    $this->post(route('accounting.bankAccountStore', $f['uuid']), [
        'ledger_account_id' => $f['account1'],
        'bank_name' => 'Banco Industrial',
        'account_name' => 'Monetaria',
        'account_type' => 'checking',
        'last_four' => '1234',
        'bank_balance' => '100.00',
    ])->assertForbidden();
    $this->post(route('accounting.account', $f['uuid']), ['code' => '5101', 'name' => 'Gastos', 'type' => 'expense'])->assertForbidden();
});

test('fine grained operational role grants only its allowed module', function () {
    $f = accountingFixture();
    $treasury = User::factory()->create();
    $f['org']->users()->attach($treasury->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($treasury->id, ['role' => 'member', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    app(CompanyAssignmentService::class)->assign($f['org'], $f['owner'], $f['office']->id, $f['companyId'], $treasury->id, 'treasury');
    app(WorkAuthorizationService::class)->assignRole($f['org'], $f['owner'], $treasury->id, 'treasury', $f['office']->id);

    $this->actingAs($treasury)
        ->post(route('accounting.bankAccountStore', $f['uuid']), [
            'ledger_account_id' => $f['account1'],
            'bank_name' => 'Banco Industrial',
            'account_name' => 'Monetaria',
            'account_type' => 'checking',
            'last_four' => '1234',
            'bank_balance' => '100.00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('bank_accounts', ['company_id' => $f['companyId'], 'bank_name' => 'Banco Industrial']);

    $this->patch(route('accounting.fiscalSettingsUpdate', $f['uuid']), [
        'country_code' => 'GT',
        'base_currency' => 'GTQ',
        'timezone' => 'America/Guatemala',
        'vat_regime' => 'general',
        'income_tax_regime' => 'utilities',
        'accounting_template' => 'guatemala_general',
        'exchange_rate_source' => 'BANGUAT',
        'fiscal_year_starts_in' => 1,
    ])->assertForbidden();
    $this->post(route('accounting.account', $f['uuid']), ['code' => '5101', 'name' => 'Gastos', 'type' => 'expense'])->assertForbidden();
});

test('revocation prevents access and foreign or unassigned office members cannot be granted permission', function () {
    $f = accountingFixture();
    $other = accountingFixture();
    $member = User::factory()->create();
    $f['org']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $this->actingAs($f['owner'])->post(route('accounting.assign', $f['uuid']), ['user_id' => $other['owner']->id, 'permission' => 'editor'])->assertNotFound();
    $this->post(route('accounting.assign', $f['uuid']), ['user_id' => $member->id, 'permission' => 'editor'])->assertStatus(422);
    $f['office']->users()->attach($member->id, ['status' => 'active']);
    $this->post(route('accounting.assign', $f['uuid']), ['user_id' => $member->id, 'permission' => 'editor'])->assertRedirect();
    $this->post(route('accounting.assign', $f['uuid']), ['user_id' => $member->id, 'permission' => 'none'])->assertRedirect();
    $this->actingAs($member)->get(route('accounting.show', $f['uuid']))->assertNotFound();
});

test('invalid amounts unbalanced entries foreign accounts and foreign periods are rejected atomically', function () {
    $f = accountingFixture();
    $other = accountingFixture();
    $this->actingAs($f['owner']);
    foreach (['1000.11', '-1000.10', '1000.101', '1e3', 'NaN', ['value' => '1000.10']] as $amount) {
        $data = accountingDraft($f);
        $data['lines'][0]['debit'] = $amount;
        $this->post(route('accounting.entry', $f['uuid']), $data)->assertSessionHasErrors('accounting');
    }
    $data = accountingDraft($f);
    $data['lines'][0]['credit'] = '1';
    $this->post(route('accounting.entry', $f['uuid']), $data)->assertSessionHasErrors('accounting');
    $data = accountingDraft($f);
    $data['lines'][0]['account_id'] = $other['account1'];
    $this->post(route('accounting.entry', $f['uuid']), $data)->assertSessionHasErrors('accounting');
    $data = accountingDraft($f);
    $data['period_id'] = $other['period'];
    $this->post(route('accounting.entry', $f['uuid']), $data)->assertSessionHasErrors('accounting');
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('journal_lines', 0);
});

test('drafts do not affect books posting is exact numbered audited and cannot repeat', function () {
    $f = accountingFixture();
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data)->assertRedirect();
    $this->post(route('accounting.entry', $f['uuid']), $data)->assertSessionHasErrors('accounting');
    $this->get(route('accounting.show', $f['uuid']))->assertInertia(fn (Assert $p) => $p
        ->has('balances', 0)
        ->has('journal.data', 1)
        ->where('periods.0.entries_count', 1)
        ->where('periods.0.draft_count', 1)
        ->where('periods.0.posted_count', 0)
        ->where('periods.0.debit_cents', 0)
        ->where('periods.0.credit_cents', 0));
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post'])->assertRedirect();
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post'])->assertSessionHasErrors('accounting');
    $this->get(route('accounting.show', ['company' => $f['uuid'], 'account' => $f['account1']]))->assertInertia(fn (Assert $p) => $p
        ->has('balances', 2)
        ->where('balances.0.debit_cents', fn ($v) => (int) $v === 100010)
        ->has('ledger.data', 1)
        ->where('periods.0.entries_count', 1)
        ->where('periods.0.draft_count', 0)
        ->where('periods.0.posted_count', 1)
        ->where('periods.0.debit_cents', 100010)
        ->where('periods.0.credit_cents', 100010));
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'posted', 'number' => 1, 'posted_by' => $f['owner']->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'entry.post', 'user_id' => $f['owner']->id]);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('journal workflow submits reviews approves and posts with formal traceability', function () {
    $f = accountingFixture();
    $data = [...accountingDraft($f), 'source_type' => 'sales_invoice', 'source_id' => 125, 'accounting_event' => 'sale.invoice.issued', 'posting_key' => 'sale_invoice:125:issued'];
    $data['lines'][0]['description'] = 'Cliente Los Pinos';
    $data['lines'][0]['third_party_type'] = 'customer';
    $data['lines'][0]['third_party_id'] = 125;
    $data['lines'][0]['cost_center'] = 'Administración';
    $data['lines'][0]['project'] = 'Contrato anual';

    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data)->assertRedirect();
    $this->assertDatabaseHas('journal_entries', [
        'public_id' => $data['request_id'],
        'status' => 'draft',
        'source_type' => 'sales_invoice',
        'source_id' => 125,
        'accounting_event' => 'sale.invoice.issued',
        'posting_key' => 'sale_invoice:125:issued',
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'description' => 'Cliente Los Pinos',
        'third_party_type' => 'customer',
        'third_party_id' => 125,
        'cost_center' => 'Administración',
        'project' => 'Contrato anual',
    ]);

    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'submit'])->assertRedirect();
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'pending_review']);

    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'review'])->assertRedirect();
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'reviewed', 'reviewed_by' => $f['owner']->id]);

    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'approve'])->assertRedirect();
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'approved', 'approved_by' => $f['owner']->id]);

    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post'])->assertRedirect();
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'posted', 'number' => 1, 'posted_by' => $f['owner']->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'journal_entry.review_requested']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'journal_entry.reviewed']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'journal_entry.approved']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'journal_entry.posted']);
});

test('journal posting key prevents duplicated accounting proposals', function () {
    $f = accountingFixture();
    $data = [...accountingDraft($f), 'posting_key' => 'purchase_document:91:approved', 'source_type' => 'purchase_document', 'source_id' => 91, 'accounting_event' => 'purchase.document.approved'];
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data)->assertRedirect();

    $second = [...accountingDraft($f), 'posting_key' => 'purchase_document:91:approved', 'source_type' => 'purchase_document', 'source_id' => 91, 'accounting_event' => 'purchase.document.approved'];
    $this->post(route('accounting.entry', $f['uuid']), $second)->assertSessionHasErrors('accounting');

    $this->assertDatabaseCount('journal_entries', 1);
});

test('journal rejects inactive grouped or non movement accounts', function () {
    $f = accountingFixture();
    DB::table('ledger_accounts')->where('id', $f['account1'])->update(['is_active' => false]);

    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertSessionHasErrors('accounting');
    $this->assertDatabaseCount('journal_entries', 0);

    DB::table('ledger_accounts')->where('id', $f['account1'])->update(['is_active' => true, 'accepts_movements' => false]);
    $this->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertSessionHasErrors('accounting');

    DB::table('ledger_accounts')->where('id', $f['account1'])->update(['accepts_movements' => true, 'is_group' => true]);
    $this->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertSessionHasErrors('accounting');
    $this->assertDatabaseCount('journal_entries', 0);
});

test('period overlap out of range dates and opening date are enforced', function () {
    $f = accountingFixture();
    $this->actingAs($f['owner'])->post(route('accounting.period', $f['uuid']), ['name' => 'Overlap', 'starts_on' => '2026-08-31', 'ends_on' => '2026-09-15'])->assertSessionHasErrors('starts_on');
    foreach (['2026-09-01', '2026-08-02'] as $date) {
        $this->post(route('accounting.entry', $f['uuid']), [...accountingDraft($f), 'entry_date' => $date])->assertSessionHasErrors('accounting');
    }
    $this->assertDatabaseCount('journal_entries', 0);
});

test('period cannot close with drafts and closing blocks later posting', function () {
    $f = accountingFixture();
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data);
    $this->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertSessionHasErrors('accounting');
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'void', 'reason' => 'Error de captura'])->assertRedirect();
    $this->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertRedirect();
    $this->post(route('accounting.entry', $f['uuid']), accountingDraft($f))->assertSessionHasErrors('accounting');
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'post'])->assertSessionHasErrors('accounting');
    $this->assertDatabaseHas('journal_entries', ['public_id' => $data['request_id'], 'status' => 'void']);
    $this->assertDatabaseCount('journal_lines', 2);
});

test('formal reopening keeps close snapshot and requires approval before execution', function () {
    $f = accountingFixture();
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data);
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'void', 'reason' => 'No procede']);
    $this->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertRedirect();
    $this->assertDatabaseCount('accounting_period_close_snapshots', 1);

    $company = DB::table('companies')->where('id', $f['companyId'])->first();
    $org = $f['owner']->organizations()->firstOrFail();
    $request = app(RequestAccountingPeriodReopening::class)->execute($org, $company, $f['period'], $f['owner'], 'Factura recibida posteriormente');
    expect($request->ok)->toBeTrue();
    app(ReopenAccountingPeriod::class)->execute($org, $company, $request->payload['request_id'], $f['owner'])
        ->ok->toBeFalse();
})->throws(ValidationException::class);

test('approved reopening reopens period without deleting the previous close', function () {
    $f = accountingFixture();
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data);
    $this->post(route('accounting.process', [$f['uuid'], $data['request_id']]), ['action' => 'void', 'reason' => 'No procede']);
    $this->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertRedirect();

    $company = DB::table('companies')->where('id', $f['companyId'])->first();
    $org = $f['owner']->organizations()->firstOrFail();
    $request = app(RequestAccountingPeriodReopening::class)->execute($org, $company, $f['period'], $f['owner'], 'Factura recibida posteriormente');
    app(ApproveAccountingPeriodReopening::class)->execute($org, $company, $request->payload['request_id'], $f['owner']);
    $result = app(ReopenAccountingPeriod::class)->execute($org, $company, $request->payload['request_id'], $f['owner']);

    expect($result->ok)->toBeTrue();
    $this->assertDatabaseHas('accounting_periods', ['id' => $f['period'], 'status' => 'reopened', 'closed_at' => null]);
    $this->assertDatabaseHas('accounting_period_reopening_requests', ['id' => $request->payload['request_id'], 'status' => 'executed']);
    $this->assertDatabaseCount('accounting_period_close_snapshots', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_period.reopened']);
});

test('period reopening routes request approve and execute the formal flow', function () {
    $f = accountingFixture();
    $this->actingAs($f['owner'])->post(route('accounting.close', [$f['uuid'], $f['period']]))->assertRedirect();

    $this->post(route('accounting.periods.reopen.request', [$f['uuid'], $f['period']]), [
        'reason' => 'Factura de proveedor recibida posteriormente',
    ])->assertRedirect();

    $requestId = DB::table('accounting_period_reopening_requests')->value('id');

    $this->assertDatabaseHas('accounting_periods', ['id' => $f['period'], 'status' => 'reopen_requested']);
    $this->assertDatabaseHas('accounting_period_reopening_requests', ['id' => $requestId, 'status' => 'pending']);

    $this->post(route('accounting.periods.reopen.approve', [$f['uuid'], $requestId]))->assertRedirect();
    $this->assertDatabaseHas('accounting_periods', ['id' => $f['period'], 'status' => 'reopen_approved']);

    $this->post(route('accounting.periods.reopen.execute', [$f['uuid'], $requestId]))->assertRedirect();

    $this->assertDatabaseHas('accounting_periods', ['id' => $f['period'], 'status' => 'reopened', 'closed_at' => null]);
    $this->assertDatabaseHas('accounting_period_reopening_requests', ['id' => $requestId, 'status' => 'executed']);
    $this->assertDatabaseCount('accounting_period_close_snapshots', 1);
});

test('posted entries cannot be discarded and linked reversal preserves original and zeroes net balances', function () {
    $f = accountingFixture();
    $data = accountingDraft($f);
    $this->actingAs($f['owner'])->post(route('accounting.entry', $f['uuid']), $data);
    $url = route('accounting.process', [$f['uuid'], $data['request_id']]);
    $this->post($url, ['action' => 'post']);
    $original = DB::table('journal_entries')->where('public_id', $data['request_id'])->first();
    $this->post($url, ['action' => 'void', 'reason' => 'No permitido'])->assertSessionHasErrors('accounting');
    $reverse = ['action' => 'reverse', 'period_id' => $f['period'], 'entry_date' => '2026-08-02', 'reason' => 'Corrección documentada'];
    $this->post($url, $reverse)->assertRedirect();
    $this->post($url, $reverse)->assertSessionHasErrors('accounting');
    $this->assertDatabaseHas('journal_entries', ['id' => $original->id, 'status' => 'reversed']);
    $this->assertDatabaseHas('journal_entries', ['reversal_of' => $original->id, 'status' => 'posted', 'number' => 2]);
    $this->get(route('accounting.show', $f['uuid']))->assertInertia(fn (Assert $p) => $p->where('balances', fn ($rows) => collect($rows)->every(fn ($row) => $row['debit_cents'] === $row['credit_cents'])));
    $this->assertDatabaseCount('journal_entries', 2);
});

test('guest and unpaid organizations cannot use accounting', function () {
    $f = accountingFixture();
    $this->get(route('accounting.index'))->assertRedirect(route('login'));
    $f['org']->subscriptions()->update(['status' => 'incomplete']);
    $this->actingAs($f['owner'])->get(route('accounting.index'))->assertRedirect(route('billing.checkout.show'));
});
