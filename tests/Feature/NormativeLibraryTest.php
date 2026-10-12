<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Normative\NormativeLibraryService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('the shared catalog loads the requested laws, guides and NIIF summaries idempotently', function () {
    $library = app(NormativeLibraryService::class);
    $first = $library->syncCatalog();
    $second = $library->syncCatalog();

    expect($first)->toBe($second)->and($first['documents'])->toBe(7)->and($first['sections'])->toBeGreaterThan(1100)
        ->and(DB::table('normative_documents')->whereNull('organization_id')->pluck('code')->sort()->values()->all())
        ->toBe(['ATL-GUIA-BG', 'ATL-GUIA-EFE', 'ATL-GUIA-ER', 'ATL-NIIF', 'ATL-NIIF-PYMES', 'GT-DEC-10-2012', 'GT-DEC-2-70']);

    $lat = DB::table('normative_documents')->where('code', 'GT-DEC-10-2012')->first();
    expect($lat->status)->toBe('vigente_con_reformas')->and($lat->warning)->toContain('Decreto 13-2026');
    $article53 = DB::table('normative_sections')->where('normative_document_id', $lat->id)->where('key', 'libro-1-art-53')->first();
    expect($article53->heading)->toBe('Libros y registros')->and($article53->content)->toContain('estado de flujo de efectivo')->and($article53->path)->toStartWith('Libro I');
    expect(DB::table('normative_sections')->where('normative_document_id', $lat->id)->where('key', 'libro-1-art-72')->value('reform_note'))->toContain('Decreto 13-2026');

    $niif = DB::table('normative_documents')->where('code', 'ATL-NIIF')->first();
    expect($niif->is_full_text)->toBeFalse()->and($niif->license_note)->toContain('IFRS Foundation');
    expect(DB::table('normative_sections')->where('normative_document_id', DB::table('normative_documents')->where('code', 'ATL-GUIA-EFE')->value('id'))->pluck('key')->all())->toContain('bajo-isr', 'bajo-niif', 'metodos');
});

test('search ranks the right articles and a book filter does not leak into other books', function () {
    $library = app(NormativeLibraryService::class);
    $library->syncCatalog();
    $f = libraryFixture();

    $books = collect($library->search($f['org']->id, 'libros de estados financieros', 5));
    expect($books->contains(fn (array $row) => $row['document_code'] === 'GT-DEC-2-70' && $row['label'] === 'Artículo 377'))->toBeTrue();
    $flow = $library->search($f['org']->id, 'método indirecto', 3);
    expect($flow[0]['document_code'])->toBe('ATL-GUIA-EFE');
    $deductions = collect($library->search($f['org']->id, 'deducción gastos personales', 3));
    expect($deductions->firstWhere('key', 'libro-1-art-72')['reform_note'])->toContain('Decreto 13-2026');

    $book = $library->read($f['org']->id, 'GT-DEC-10-2012', 'Libro I');
    expect($book['total'])->toBeGreaterThan(90)
        ->and(collect($book['sections'])->every(fn (array $section) => $section['path'] === 'Libro I' || str_starts_with((string) $section['path'], 'Libro I ·') || str_starts_with((string) $section['path'], 'Libro I (')))->toBeTrue()
        ->and($book['parts'])->toContain('Libro I');
});

test('the library pages are available to members, and private documents never cross tenants', function () {
    app(NormativeLibraryService::class)->syncCatalog();
    $f = libraryFixture();
    $other = libraryFixture('Otra Firma');
    $private = DB::table('normative_documents')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $other['org']->id, 'code' => 'POL-INTERNA', 'title' => 'Política interna secreta',
        'short_title' => 'Política interna', 'category' => 'interna', 'document_type' => 'politica', 'issuer' => 'Otra Firma', 'status' => 'vigente', 'version' => '1', 'summary' => 'Uso interno',
        'is_full_text' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('normative_sections')->insert(['normative_document_id' => $private, 'organization_id' => $other['org']->id, 'key' => 'uno', 'label' => 'Punto 1', 'content' => 'Reserva legal confidencial de la otra firma', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($f['member'])->get(route('library.index', ['q' => 'reserva legal']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('library/index')->has('documents', 7)
            ->where('results', fn ($results) => collect($results)->pluck('document_code')->contains('GT-DEC-2-70') && ! collect($results)->pluck('document_code')->contains('POL-INTERNA')));
    $this->get(route('library.show', ['code' => 'GT-DEC-10-2012', 'path' => 'Libro I']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('library/show')->where('document.code', 'GT-DEC-10-2012')->where('path', 'Libro I'));
    $this->get(route('library.show', 'POL-INTERNA'))->assertNotFound();
    $this->getJson(route('library.search', ['q' => 'flujo de efectivo']))->assertOk()->assertJsonPath('results.0.document_code', fn (string $code) => in_array($code, ['ATL-NIIF', 'ATL-NIIF-PYMES', 'ATL-GUIA-EFE'], true));
    $this->actingAs($other['member'])->get(route('library.show', 'POL-INTERNA'))->assertOk();
    auth()->logout();
    $this->get(route('library.index'))->assertRedirect();
});

test('Luzma answers normative questions from the library and cites document and article', function () {
    app(NormativeLibraryService::class)->syncCatalog();
    $f = libraryFixture();
    Http::fake([
        '*/ai/luzma/plan' => Http::response(['intent' => 'general', 'tool' => '', 'arguments' => [], 'confidence' => 0]),
        '*/ai/luzma/answer' => Http::response(['status' => 'ok', 'model' => 'qwen3', 'response' => 'Según el Código de Comercio, artículo 36...']),
    ]);

    $this->actingAs($f['member'])->postJson(route('luzma.chat'), ['message' => '¿Qué dice el Código de Comercio sobre la reserva legal?'])->assertOk()
        ->assertJsonPath('message.tools.0', 'search_normative')
        ->assertJsonPath('message.references.0.href', fn (string $href) => str_starts_with($href, '/library/GT-DEC-2-70#'));
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/answer')
        && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'Texto legal · Código de Comercio · Artículo 36')
        && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'distingue el texto recuperado'));

    $this->postJson(route('luzma.chat'), ['message' => '¿Qué dice la ley sobre la deducción de gastos personales?'])->assertOk();
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/answer') && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'ADVERTENCIA DE REFORMA'));
});

test('every office panel opens the shared library and internal norms open the company library screen', function () {
    app(NormativeLibraryService::class)->syncCatalog();
    $f = libraryFixture();
    foreach (['owner_director', 'office_admin', 'accounting_manager', 'general_accountant', 'accounting_supervisor', 'accountant_in_charge', 'accounting_assistant', 'tax_specialist',
        'treasury', 'accounts_receivable', 'accounts_payable', 'payroll', 'auditor'] as $role) {
        $user = User::factory()->create();
        $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
        $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
        DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
            'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $role)->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get(route('library.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('library/index')->has('documents', 7));
        $this->get(route('library.show', 'GT-DEC-2-70'))->assertOk();
        $this->getJson(route('library.search', ['q' => 'reserva legal']))->assertOk()->assertJsonPath('results.0.document_code', 'GT-DEC-2-70');
    }

    $company = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $f['office']->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $company, 'user_id' => $f['member']->id, 'assignment_role' => 'tax_specialist', 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('operational_records')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'company_id' => $company, 'module' => 'normativa', 'record_type' => 'norma', 'reference' => 'POL-001',
        'title' => 'Política de caja chica', 'status' => 'published', 'effective_on' => '2026-09-01', 'data' => '{}', 'idempotency_key' => (string) Str::uuid(), 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $publicId = (string) DB::table('companies')->where('id', $company)->value('public_id');
    $this->actingAs($f['member'])->get(route('tax.library'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('documents.0.destination', '/accounting/companies/'.$publicId.'/dashboard?module=normativa')->where('documents.0.status', 'Vigente'));
});

function libraryFixture(string $name = 'Firma Biblioteca'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $member = User::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $office->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'user_id' => $member->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $org->id)->where('code', 'tax_specialist')->value('id'),
        'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    return compact('org', 'office', 'owner', 'member');
}
