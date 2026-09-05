<?php

use App\Models\Office;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 8, 26));
    Storage::fake('local');
});

/** @return array{owner: User, organization: Organization, office: Office} */
function purchaseTenantWorkspace(string $name): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => $name, 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'essential'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create([
        'code' => 'MAIN-'.Str::upper(Str::random(5)),
        'name' => 'Sede principal',
        'status' => 'active',
        'is_primary' => true,
    ]);

    return compact('owner', 'organization', 'office');
}

/**
 * @param  array{owner: User, organization: Organization, office: Office}  $workspace
 * @return array<string, mixed>
 */
function purchaseTenantCompany(array $workspace, string $name): array
{
    $publicId = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId([
        'public_id' => $publicId,
        'office_id' => $workspace['office']->id,
        'name' => $name,
        'tax_id' => Str::upper(Str::random(10)),
        'currency' => 'GTQ',
    ]);
    app(AccountingService::class)->installDefaultChart($companyId);
    DB::table('accounting_periods')->insert([
        'company_id' => $companyId,
        'name' => 'Agosto 2026',
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-31',
    ]);
    $mapping = app(AccountingAutomationService::class)->ensureMapping($companyId);
    $expenseAccountId = (int) DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', '5201')->value('id');
    $supplierId = DB::table('purchase_suppliers')->insertGetId([
        'company_id' => $companyId,
        'name' => 'Proveedor '.$name,
        'tax_id' => 'SUP-'.Str::upper(Str::random(6)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $draftPublicId = (string) Str::uuid();
    $draftId = DB::table('purchase_documents')->insertGetId([
        'public_id' => $draftPublicId,
        'company_id' => $companyId,
        'purchase_supplier_id' => $supplierId,
        'document_type' => 'purchase',
        'external_reference' => 'DRAFT-001',
        'status' => 'draft',
        'document_date' => '2026-08-10',
        'due_on' => '2026-08-31',
        'total_cents' => 5000,
        'supplier_name' => 'Proveedor '.$name,
        'created_by' => $workspace['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('purchase_document_lines')->insert([
        'purchase_document_id' => $draftId,
        'ledger_account_id' => $expenseAccountId,
        'position' => 1,
        'description' => 'Servicio de prueba',
        'quantity' => 1,
        'unit_cents' => 5000,
    ]);

    $approvedPublicId = (string) Str::uuid();
    $approvedId = DB::table('purchase_documents')->insertGetId([
        'public_id' => $approvedPublicId,
        'company_id' => $companyId,
        'purchase_supplier_id' => $supplierId,
        'number' => 1,
        'document_type' => 'purchase',
        'external_reference' => 'APPROVED-001',
        'status' => 'approved',
        'document_date' => '2026-08-10',
        'due_on' => '2026-08-31',
        'total_cents' => 5000,
        'supplier_name' => 'Proveedor '.$name,
        'created_by' => $workspace['owner']->id,
        'approved_by' => $workspace['owner']->id,
        'approved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('purchase_document_lines')->insert([
        'purchase_document_id' => $approvedId,
        'ledger_account_id' => $expenseAccountId,
        'position' => 1,
        'description' => 'Servicio aprobado',
        'quantity' => 1,
        'unit_cents' => 5000,
    ]);
    $paymentId = DB::table('purchase_payments')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'purchase_document_id' => $approvedId,
        'amount_cents' => 1000,
        'paid_on' => '2026-08-20',
        'reference' => 'PAY-001',
        'created_by' => $workspace['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $filePublicId = (string) Str::uuid();
    $filePath = "purchases/{$publicId}/{$draftPublicId}/{$filePublicId}.pdf";
    Storage::disk('local')->put($filePath, 'evidence');
    DB::table('purchase_document_files')->insert([
        'public_id' => $filePublicId,
        'purchase_document_id' => $draftId,
        'uploaded_by' => $workspace['owner']->id,
        'original_name' => 'evidence.pdf',
        'storage_path' => $filePath,
        'mime_type' => 'application/pdf',
        'size_bytes' => 8,
        'sha256' => hash('sha256', 'evidence'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        ...$workspace,
        'publicId' => $publicId,
        'companyId' => $companyId,
        'supplierId' => $supplierId,
        'expenseAccountId' => $expenseAccountId,
        'mapping' => $mapping,
        'draftPublicId' => $draftPublicId,
        'approvedPublicId' => $approvedPublicId,
        'paymentId' => $paymentId,
        'filePublicId' => $filePublicId,
        'filePath' => $filePath,
    ];
}

/** @param array<string, mixed> $company */
function purchaseTenantEndpoints(array $company): array
{
    $mapping = $company['mapping'];

    return [
        'read' => [
            ['get', route('accounting.purchases', $company['publicId']), []],
            ['get', route('accounting.purchasesExport', ['company' => $company['publicId'], 'format' => 'pdf']), []],
            ['get', route('accounting.suppliers', $company['publicId']), []],
            ['get', route('accounting.suppliersExport', ['company' => $company['publicId'], 'format' => 'pdf']), []],
            ['get', route('accounting.payables', $company['publicId']), []],
            ['get', route('accounting.purchaseShow', [$company['publicId'], $company['draftPublicId']]), []],
            ['get', route('accounting.purchasePdf', [$company['publicId'], $company['draftPublicId']]), []],
            ['get', route('accounting.purchaseFileDownload', [$company['publicId'], $company['draftPublicId'], $company['filePublicId']]), []],
        ],
        'write' => [
            ['post', route('accounting.purchaseSupplier', $company['publicId']), ['name' => 'Proveedor autorizado', 'tax_id' => 'AUTH-001']],
            ['patch', route('accounting.purchaseSupplierUpdate', [$company['publicId'], $company['supplierId']]), ['name' => 'Proveedor actualizado', 'tax_id' => 'AUTH-002']],
            ['post', route('accounting.purchaseStore', $company['publicId']), [
                'request_id' => (string) Str::uuid(),
                'supplier_id' => $company['supplierId'],
                'document_type' => 'expense',
                'external_reference' => 'NEW-001',
                'document_date' => '2026-08-21',
                'due_on' => '2026-08-31',
                'lines' => [[
                    'account_id' => $company['expenseAccountId'],
                    'description' => 'Compra autorizada',
                    'quantity' => 1,
                    'unit_price' => '10.00',
                ]],
            ]],
            ['post', route('accounting.purchasePayment', [$company['publicId'], $company['approvedPublicId']]), [
                'request_id' => (string) Str::uuid(),
                'amount' => '5.00',
                'paid_on' => '2026-08-26',
                'reference' => 'PAY-AUTH-001',
            ]],
            ['post', route('accounting.purchaseFileStore', [$company['publicId'], $company['draftPublicId']]), [
                'file' => UploadedFile::fake()->create('new-evidence.pdf', 10, 'application/pdf'),
            ]],
            ['delete', route('accounting.purchaseFileDestroy', [$company['publicId'], $company['draftPublicId'], $company['filePublicId']]), []],
        ],
        'owner' => [
            ['post', route('accounting.purchaseProcess', [$company['publicId'], $company['draftPublicId']]), ['action' => 'approve']],
            ['post', route('accounting.purchasePaymentVoid', [$company['publicId'], $company['approvedPublicId'], $company['paymentId']]), ['reason' => 'Anulación controlada']],
            ['patch', route('accounting.accountingMapping', $company['publicId']), [
                'cash_account_id' => $mapping->cash_account_id,
                'receivable_account_id' => $mapping->receivable_account_id,
                'revenue_account_id' => $mapping->revenue_account_id,
                'payable_account_id' => $mapping->payable_account_id,
                'default_expense_account_id' => $mapping->default_expense_account_id,
            ]],
        ],
    ];
}

/** @param array<string, mixed> $company */
function purchaseTenantDatabaseFingerprint(array $company): array
{
    $documentIds = DB::table('purchase_documents')->where('company_id', $company['companyId'])->pluck('id');

    return [
        'suppliers' => DB::table('purchase_suppliers')->where('company_id', $company['companyId'])->count(),
        'documents' => $documentIds->count(),
        'payments' => DB::table('purchase_payments')->whereIn('purchase_document_id', $documentIds)->count(),
        'files' => DB::table('purchase_document_files')->whereIn('purchase_document_id', $documentIds)->count(),
        'entries' => DB::table('accounting_source_entries')->where('company_id', $company['companyId'])->count(),
        'audits' => DB::table('audit_logs')->where('organization_id', $company['organization']->id)->count(),
    ];
}

test('an owner from another organization cannot reach any purchase endpoint', function () {
    $target = purchaseTenantCompany(purchaseTenantWorkspace('Organización objetivo'), 'Empresa objetivo');
    $attacker = purchaseTenantCompany(purchaseTenantWorkspace('Organización atacante'), 'Empresa atacante');
    $before = purchaseTenantDatabaseFingerprint($target);

    $this->actingAs($attacker['owner']);
    foreach (array_merge(...array_values(purchaseTenantEndpoints($target))) as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertNotFound();
    }

    expect(purchaseTenantDatabaseFingerprint($target))->toBe($before);
    Storage::disk('local')->assertExists($target['filePath']);
});

test('a member assigned to another company in the same organization cannot reach any target purchase endpoint', function () {
    $workspace = purchaseTenantWorkspace('Organización compartida');
    $target = purchaseTenantCompany($workspace, 'Empresa restringida');
    $assigned = purchaseTenantCompany($workspace, 'Empresa asignada');
    $member = User::factory()->create();
    $workspace['organization']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $workspace['office']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $assigned['companyId'], 'user_id' => $member->id, 'permission' => 'editor']);
    $before = purchaseTenantDatabaseFingerprint($target);

    $this->actingAs($member);
    $endpoints = purchaseTenantEndpoints($target);
    foreach (array_merge($endpoints['read'], $endpoints['write']) as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertNotFound();
    }
    foreach ($endpoints['owner'] as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertForbidden();
    }

    expect(purchaseTenantDatabaseFingerprint($target))->toBe($before);
    Storage::disk('local')->assertExists($target['filePath']);
});

test('a company viewer can use every read endpoint but no purchase mutation endpoint', function () {
    $company = purchaseTenantCompany(purchaseTenantWorkspace('Organización visor'), 'Empresa visor');
    $viewer = User::factory()->create();
    $company['organization']->users()->attach($viewer->id, ['role' => 'member', 'status' => 'active']);
    $company['office']->users()->attach($viewer->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $company['companyId'], 'user_id' => $viewer->id, 'permission' => 'viewer']);
    $before = purchaseTenantDatabaseFingerprint($company);
    $endpoints = purchaseTenantEndpoints($company);

    $this->actingAs($viewer);
    foreach ($endpoints['read'] as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertOk();
    }
    foreach (array_merge($endpoints['write'], $endpoints['owner']) as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertForbidden();
    }

    expect(purchaseTenantDatabaseFingerprint($company))->toBe($before);
    Storage::disk('local')->assertExists($company['filePath']);
});

test('a company editor can perform operational writes but cannot approve void or remap accounting', function () {
    $company = purchaseTenantCompany(purchaseTenantWorkspace('Organización editor'), 'Empresa editor');
    $editor = User::factory()->create();
    $company['organization']->users()->attach($editor->id, ['role' => 'member', 'status' => 'active']);
    $company['office']->users()->attach($editor->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $company['companyId'], 'user_id' => $editor->id, 'permission' => 'editor']);
    $endpoints = purchaseTenantEndpoints($company);

    $this->actingAs($editor);
    foreach ($endpoints['read'] as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertOk();
    }
    foreach ($endpoints['owner'] as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertForbidden();
    }
    foreach ($endpoints['write'] as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertRedirect();
    }

    $this->assertDatabaseHas('purchase_suppliers', ['company_id' => $company['companyId'], 'name' => 'Proveedor autorizado']);
    $this->assertDatabaseHas('purchase_documents', ['company_id' => $company['companyId'], 'external_reference' => 'NEW-001']);
    $approvedId = DB::table('purchase_documents')->where('public_id', $company['approvedPublicId'])->value('id');
    $this->assertDatabaseHas('purchase_payments', ['purchase_document_id' => $approvedId, 'amount_cents' => 500]);
    Storage::disk('local')->assertMissing($company['filePath']);
});

test('guests are redirected before every purchase endpoint is evaluated', function () {
    $company = purchaseTenantCompany(purchaseTenantWorkspace('Organización invitado'), 'Empresa invitado');

    foreach (array_merge(...array_values(purchaseTenantEndpoints($company))) as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertRedirect(route('login'));
    }
});
