<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** @return array{uuid: string, companyId: int, owner: User, org: Organization, officeId: int, users: array<string, User>} */
function journalImportFixture(): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Importación '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $auth = app(WorkAuthorizationService::class);
    $auth->ensureDefaults($org);
    $owner = $owner->fresh();
    $org = $owner->organizations()->whereKey($org->id)->firstOrFail();
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Empresa Importación, S.A.', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    DB::table('accounting_periods')->insert(['company_id' => $companyId, 'name' => '2026-02', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28']);

    $users = [];
    foreach (['accountant_in_charge' => 'accountant_in_charge', 'accounting_assistant' => 'accounting_assistant', 'accounting_supervisor' => 'supervisor'] as $role => $assignment) {
        $user = User::factory()->create();
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('office_user')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $auth->assignRole($org, $owner, $user->id, $role, $office->id);
        app(CompanyAssignmentService::class)->assign($org, $owner, $office->id, $companyId, $user->id, $assignment, false, [], true);
        $users[$role] = $user;
    }

    return ['uuid' => $uuid, 'companyId' => $companyId, 'owner' => $owner, 'org' => $org, 'officeId' => $office->id, 'users' => $users];
}

function journalCsv(string $content, string $name = 'asientos.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

const JOURNAL_HEADER = 'grupo,fecha,periodo_id,tipo,concepto,referencia,codigo_cuenta,descripcion_linea,tercero_tipo,tercero_id,centro_costo,proyecto,debe,haber';

function journalRows(string $group, string $reference, string $amount, string $delimiter = ','): string
{
    return implode("\n", array_map(fn (array $row): string => implode($delimiter, $row), [
        [$group, '2026-02-10', '', 'standard', 'Compra de prueba', $reference, '1201', 'Inventario', '', '', '', '', $amount, '0'],
        [$group, '2026-02-10', '', 'standard', 'Compra de prueba', $reference, '2102', 'Proveedor', '', '', '', '', '0', $amount],
    ]));
}

test('a file saved by a spreadsheet, with byte-order mark and semicolons, is accepted', function () {
    $f = journalImportFixture();
    $content = "\xEF\xBB\xBF".str_replace(',', ';', JOURNAL_HEADER)."\n".journalRows('A-1', 'C-100', '500.00', ';')."\n";

    $this->actingAs($f['owner'])->post(route('accounting.entryImportPreview', $f['uuid']), ['file' => journalCsv($content)])->assertSessionHasNoErrors();

    $preview = session('journal_import_preview');
    expect($preview['errors'])->toBe([])->and($preview['entries'])->toHaveCount(1);
});

test('a file that is not the template says which columns it needs', function () {
    $f = journalImportFixture();

    $this->actingAs($f['owner'])->post(route('accounting.entryImportPreview', $f['uuid']), ['file' => journalCsv("a,b,c\n1,2,3\n")])->assertSessionHasErrors('file');
    expect(session('errors')->get('file')[0])->toContain('grupo, fecha');
});

test('the same reference and amount twice in the file is flagged as a possible duplicate', function () {
    $f = journalImportFixture();
    $content = JOURNAL_HEADER."\n".journalRows('A-1', 'C-002', '33600.00')."\n".journalRows('A-2', 'C-002', '33600.00')."\n";

    $this->actingAs($f['owner'])->post(route('accounting.entryImportPreview', $f['uuid']), ['file' => journalCsv($content)]);

    expect(session('journal_import_preview')['errors'][0])->toContain('C-002')->toContain('duplicado');
    $this->post(route('accounting.entryImport', $f['uuid']), ['file' => journalCsv($content)])->assertSessionHasErrors('file');
    expect(DB::table('journal_entries')->count())->toBe(0);
});

test('a reference already in the books with the same amount is flagged, a rejected one is not, a different amount is not', function () {
    $f = journalImportFixture();
    $first = JOURNAL_HEADER."\n".journalRows('A-1', 'C-002', '33600.00')."\n";
    $this->actingAs($f['owner'])->post(route('accounting.entryImport', $f['uuid']), ['file' => journalCsv($first)])->assertSessionHasNoErrors();
    expect(DB::table('journal_entries')->where('reference', 'C-002')->count())->toBe(1);

    $this->post(route('accounting.entryImportPreview', $f['uuid']), ['file' => journalCsv(JOURNAL_HEADER."\n".journalRows('A-9', 'c-002', '33600.00')."\n")]);
    expect(session('journal_import_preview')['errors'][0])->toContain('ya existe en el Libro Diario');

    $this->post(route('accounting.entryImportPreview', $f['uuid']), ['file' => journalCsv(JOURNAL_HEADER."\n".journalRows('A-9', 'C-002', '100.00')."\n")]);
    expect(session('journal_import_preview')['errors'])->toBe([]);

    DB::table('journal_entries')->where('reference', 'C-002')->update(['status' => 'rejected']);
    $this->post(route('accounting.entryImportPreview', $f['uuid']), ['file' => journalCsv(JOURNAL_HEADER."\n".journalRows('A-9', 'C-002', '33600.00')."\n")]);
    expect(session('journal_import_preview')['errors'])->toBe([]);
});

test('the accountant in charge and the assistant prepare drafts by import, the supervisor does not', function () {
    $f = journalImportFixture();
    $content = JOURNAL_HEADER."\n".journalRows('A-1', 'C-300', '800.00')."\n";

    $this->actingAs($f['users']['accountant_in_charge'])->post(route('accounting.entryImport', $f['uuid']), ['file' => journalCsv($content)])->assertSessionHasNoErrors();
    $draft = DB::table('journal_entries')->where('reference', 'C-300')->first();
    expect($draft->status)->toBe('draft');

    $other = JOURNAL_HEADER."\n".journalRows('A-2', 'C-301', '900.00')."\n";
    $this->actingAs($f['users']['accounting_assistant'])->post(route('accounting.entryImport', $f['uuid']), ['file' => journalCsv($other)])->assertSessionHasNoErrors();
    expect(DB::table('journal_entries')->where('reference', 'C-301')->exists())->toBeTrue();

    $this->actingAs($f['users']['accounting_supervisor'])->post(route('accounting.entryImport', $f['uuid']), ['file' => journalCsv(JOURNAL_HEADER."\n".journalRows('A-3', 'C-302', '10.00')."\n")])->assertForbidden();
});
