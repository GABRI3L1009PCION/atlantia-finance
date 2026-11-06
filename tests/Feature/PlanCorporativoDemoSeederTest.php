<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\Integrations\WebhookDeliveryService;
use App\Services\Integrations\WebhookEndpointService;
use App\Services\Platform\EntitlementResolverService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCorporativoDemoCatalog;
use Database\Seeders\PlanCorporativoDemoSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
    $this->seed(PlanCorporativoDemoSeeder::class);
    $this->organization = Organization::query()->where('tax_identifier', PlanCorporativoDemoCatalog::ORGANIZATION_TAX_ID)->firstOrFail();
    $this->companies = DB::table('companies')->where('organization_id', $this->organization->id)->pluck('id');
});

function corporateDemoUser(string $key): User
{
    $email = collect(PlanCorporativoDemoCatalog::team())->firstWhere('key', $key)['email'].'@'.PlanCorporativoDemoCatalog::DOMAIN;

    return User::query()->where('email', $email)->firstOrFail();
}

function corporateDemoCompany(string $name): stdClass
{
    return DB::table('companies')->where('name', $name)->firstOrFail();
}

/**
 * Llave temporal creada solo dentro de la prueba: se guarda su huella SHA-256 y el valor en claro vive en memoria.
 *
 * @param  list<string>  $abilities
 * @param  list<int>|null  $companyIds
 */
function corporateTemporaryToken(Organization $organization, User $user, array $abilities, ?array $companyIds = null): string
{
    $prefix = 'atl_'.Str::lower(Str::random(8));
    $plain = $prefix.'_'.Str::random(40);
    DB::table('api_tokens')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'user_id' => $user->id, 'name' => 'Llave temporal de prueba',
        'token_prefix' => $prefix, 'token_hash' => hash('sha256', $plain), 'abilities' => json_encode($abilities), 'company_ids' => $companyIds === null ? null : json_encode($companyIds),
        'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

    return $plain;
}

test('the Corporativo demo has eight offices, up to sixty internal users, custom roles and the director in supervision only', function () {
    $clientRole = DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('code', 'client_user')->value('id');
    $internal = DB::table('organization_user')->where('organization_id', $this->organization->id)->where('status', 'active')
        ->whereNotIn('user_id', DB::table('user_role_assignments')->where('workspace_role_id', $clientRole)->select('user_id'))->count();
    $offices = DB::table('offices')->where('organization_id', $this->organization->id)->whereNull('deleted_at');

    expect((clone $offices)->count())->toBe(8)
        ->and((clone $offices)->distinct()->pluck('review_workflow')->sort()->values()->all())->toBe(['double_level', 'single_level'])
        ->and($internal)->toBe(49)->toBeLessThanOrEqual(60)
        ->and(DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->whereIn('subscription_id', $this->organization->subscriptions()->pluck('id'))->value('plans.code'))->toBe('corporate')
        ->and($this->companies)->toHaveCount(96)
        ->and(DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('is_system', false)->whereNull('deleted_at')->count())->toBe(4);

    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->isExecutiveReadOnly($this->organization, corporateDemoUser('owner')))->toBeTrue()
        ->and($authorization->can($this->organization, corporateDemoUser('owner'), 'journal.post'))->toBeFalse()
        ->and($authorization->primaryRole($this->organization, corporateDemoUser('admin-general')))->toBe('office_admin')
        ->and($authorization->can($this->organization, corporateDemoUser('assistant-cen'), 'journal.post'))->toBeFalse()
        ->and($authorization->can($this->organization, corporateDemoUser('coord-reportes'), 'reports.export'))->toBeTrue()
        ->and($authorization->can($this->organization, corporateDemoUser('coord-reportes'), 'journal.post'))->toBeFalse();

    // Cada contacto del portal solo ve su empresa.
    foreach (DB::table('client_company_access')->whereIn('company_id', $this->companies)->get() as $access) {
        expect(DB::table('client_company_access')->where('user_id', $access->user_id)->count())->toBe(1);
    }
});

test('the books balance, reviews follow each office workflow and the three groups consolidate with intercompany operations', function () {
    $unbalanced = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
        ->whereIn('p.company_id', $this->companies)->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->select('e.id')->get();
    expect($unbalanced)->toHaveCount(0);
    foreach ($this->companies->take(6) as $companyId) {
        expect(app(FinancialReportService::class)->statements((int) $companyId, '2026-08-01', '2026-10-29')['difference'])->toBe(0);
    }

    $reviews = DB::table('company_period_reviews')->whereIn('company_id', $this->companies)->whereNull('deleted_at');
    expect((clone $reviews)->distinct()->pluck('status')->all())->toContain('ready_for_review', 'in_review', 'changes_requested', 'first_level_approved', 'approved')
        ->and((clone $reviews)->whereColumn('submitted_by', 'first_level_approved_by')->count())->toBe(0)
        ->and((clone $reviews)->whereColumn('first_level_approved_by', 'approved_by')->whereNotNull('upper_reviewer_id')->count())->toBe(0)
        ->and(DB::table('accounting_periods')->whereIn('company_id', $this->companies)->whereNotNull('closed_at')->count())->toBeGreaterThan(0)
        ->and(DB::table('payroll_periods')->whereIn('company_id', $this->companies)->count())->toBeGreaterThan(0)
        ->and(DB::table('production_orders')->whereIn('company_id', $this->companies)->count())->toBeGreaterThan(0)
        ->and(DB::table('published_reports')->whereIn('company_id', $this->companies)->where('status', 'published')->count())->toBeGreaterThan(0)
        ->and(DB::table('training_courses')->where('organization_id', $this->organization->id)->count())->toBe(3);

    // Una sede de un solo nivel nunca deja revisiones esperando la revisión superior.
    $single = DB::table('offices')->where('organization_id', $this->organization->id)->where('review_workflow', 'single_level')->pluck('id');
    expect(DB::table('company_period_reviews')->whereIn('office_id', $single)->where('status', 'first_level_approved')->count())->toBe(0);

    $groups = DB::table('company_groups')->where('organization_id', $this->organization->id)->get();
    expect($groups)->toHaveCount(3);
    foreach (PlanCorporativoDemoCatalog::groups() as $group) {
        $row = $groups->firstWhere('name', $group['name']);
        expect((int) $row->parent_company_id)->toBe((int) corporateDemoCompany($group['parent'])->id);
        $members = DB::table('company_group_members')->where('company_group_id', $row->id)->get();
        expect($members)->toHaveCount(2)
            ->and($members->pluck('ownership_bp')->map(fn ($bp): int => (int) $bp)->all())->toContain(10000)
            ->and((int) $members->pluck('ownership_bp')->min())->toBeLessThan(10000);
    }

    // Cada venta entre empresas del grupo lleva el NIT exacto de la compradora y la compradora la registra con el NIT de la vendedora.
    foreach (PlanCorporativoDemoCatalog::intercompany() as [$seller, $buyer]) {
        $sellerCompany = corporateDemoCompany($seller);
        $buyerCompany = corporateDemoCompany($buyer);
        expect(DB::table('sales_invoices')->where('company_id', $sellerCompany->id)->where('customer_tax_id', $buyerCompany->tax_id)->count())->toBeGreaterThan(0)
            ->and(DB::table('purchase_suppliers')->where('company_id', $buyerCompany->id)->where('tax_id', $sellerCompany->tax_id)->exists())->toBeTrue();
    }
});

test('API v1 keys are stored hashed and the API answers 401, 402, 403 and 429 as the plan and the key dictate', function () {
    $tokens = DB::table('api_tokens')->where('organization_id', $this->organization->id)->get();
    expect($tokens->count())->toBeGreaterThanOrEqual(4)
        ->and($tokens->every(fn (stdClass $token): bool => strlen((string) $token->token_hash) === 64))->toBeTrue()
        ->and($tokens->whereNotNull('revoked_at'))->toHaveCount(1)
        ->and($tokens->filter(fn (stdClass $token): bool => $token->expires_at !== null && now()->greaterThan($token->expires_at)))->toHaveCount(1)
        ->and(DB::table('api_request_logs')->where('organization_id', $this->organization->id)->distinct()->pluck('status')->map(fn ($status): int => (int) $status)->all())
        ->toContain(200, 201, 401, 403);

    $erp = corporateDemoCompany('Distribuidora El Progreso, S.A.');
    $other = corporateDemoCompany('Inversiones Santo Tomás, S.A.');
    $owner = corporateDemoUser('owner');
    $restricted = corporateTemporaryToken($this->organization, $owner, ['companies:read', 'accounts:read'], [(int) $erp->id]);

    $this->getJson('/api/v1/companies')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->withToken('atl_invalida_'.Str::random(40))->getJson('/api/v1/companies')->assertStatus(401);
    $this->withToken($restricted)->getJson('/api/v1/companies/'.$erp->public_id.'/accounts')->assertOk();
    $this->withToken($restricted)->getJson('/api/v1/companies/'.$other->public_id.'/accounts')->assertStatus(403);
    $this->withToken($restricted)->getJson('/api/v1/companies/'.$erp->public_id.'/customers')->assertStatus(403);

    config(['integrations.api.rate_limit_per_minute' => 2]);
    $limited = corporateTemporaryToken($this->organization, $owner, ['companies:read']);
    $this->withToken($limited)->getJson('/api/v1/companies')->assertOk();
    $this->withToken($limited)->getJson('/api/v1/companies')->assertOk();
    $this->withToken($limited)->getJson('/api/v1/companies')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

    config(['integrations.api.rate_limit_per_minute' => 120]);
    Cache::flush();
    $this->organization->subscriptions()->update(['status' => 'cancelled']);
    app(EntitlementResolverService::class)->forget((int) $this->organization->id);
    $this->withToken($limited)->getJson('/api/v1/companies')->assertStatus(402)->assertJsonPath('error.code', 'subscription_inactive');
});

test('webhooks are signed with HMAC, refuse unsafe URLs, retry, disable and stay isolated per company and organization', function () {
    $endpoints = DB::table('webhook_endpoints')->where('organization_id', $this->organization->id)->get()->keyBy('url');
    $erp = $endpoints['https://erp.distribuidoraelprogreso.test/atlantia/webhooks'];
    $bi = $endpoints['https://bi.grupocontableatlantico.test/eventos'];
    $legacy = $endpoints['https://legado.grupocontableatlantico.test/hooks'];
    $erpCompany = corporateDemoCompany('Distribuidora El Progreso, S.A.');

    // Secretos cifrados; el ERP solo recibe eventos de su empresa; los fallos del tablero se reintentan o lo deshabilitan.
    expect(Crypt::decryptString((string) $erp->secret))->toStartWith('whsec_')->not->toBe((string) $erp->secret)
        ->and(json_decode((string) $erp->company_ids, true))->toBe([(int) $erpCompany->id])
        ->and(DB::table('webhook_deliveries')->where('webhook_endpoint_id', $erp->id)->whereNotNull('company_id')->where('company_id', '<>', $erpCompany->id)->count())->toBe(0)
        ->and(DB::table('webhook_deliveries')->where('webhook_endpoint_id', $erp->id)->where('status', 'succeeded')->count())->toBeGreaterThan(0)
        ->and(DB::table('webhook_deliveries')->where('webhook_endpoint_id', $bi->id)->where('status', 'succeeded')->count())->toBe(0)
        ->and(DB::table('webhook_deliveries')->where('webhook_endpoint_id', $bi->id)->where('response_status', 503)->count())->toBeGreaterThan(0)
        ->and($legacy->status)->toBe('disabled');

    // Firma HMAC verificable con un destino temporal creado en la prueba (servidor simulado; nada sale del proceso).
    $admin = corporateDemoUser('admin-general');
    $member = $admin->organizations()->whereKey($this->organization->id)->firstOrFail();
    $service = app(WebhookEndpointService::class);
    $created = $service->create($member, $admin, 'Destino temporal de prueba', 'https://erp.distribuidoraelprogreso.test/pruebas', ['customer.created'], [(int) $erpCompany->id]);
    $service->test($member, $admin, $created['id']);
    Http::assertSent(function (HttpRequest $request) use ($created): bool {
        if ($request->url() !== 'https://erp.distribuidoraelprogreso.test/pruebas') {
            return false;
        }
        preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('Atlantia-Signature')[0], $signature);

        return isset($signature[2]) && hash_equals(hash_hmac('sha256', $signature[1].'.'.$request->body(), $created['secret']), $signature[2]);
    });

    // URL inseguras o internas se rechazan.
    foreach (['http://erp.distribuidoraelprogreso.test/x', 'https://localhost/x', 'https://127.0.0.1/x', 'https://10.0.0.8/x', 'https://servidor.internal/x'] as $url) {
        expect(fn () => $service->create($member, $admin, 'Inseguro', $url, ['customer.created'], null))->toThrow(ValidationException::class);
    }

    // Un fallo queda pendiente con espera creciente y, tras fallas seguidas, el destino se deshabilita.
    config(['integrations.webhooks.disable_after_failures' => 2]);
    $failing = $service->create($member, $admin, 'Tablero temporal', 'https://bi.grupocontableatlantico.test/temporal', ['customer.created'], null);
    $first = DB::table('webhook_deliveries')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'webhook_endpoint_id' => $failing['id'],
        'event' => 'customer.created', 'event_id' => (string) Str::uuid(), 'payload' => json_encode(['data' => ['name' => 'Cliente de prueba']]), 'status' => 'pending', 'attempts' => 0,
        'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    app(WebhookDeliveryService::class)->deliver($first, true);
    $delivery = DB::table('webhook_deliveries')->where('id', $first)->first();
    expect($delivery->status)->toBe('pending')->and((int) $delivery->attempts)->toBe(1)->and((int) $delivery->response_status)->toBe(503);
    app(WebhookDeliveryService::class)->deliver($first, true);
    expect(DB::table('webhook_endpoints')->where('id', $failing['id'])->value('status'))->toBe('disabled');

    // Otra organización no ve ni opera los destinos de esta.
    $outsider = Organization::query()->where('id', '<>', $this->organization->id)->first();
    if ($outsider !== null) {
        expect(fn () => $service->test($outsider, $admin, (int) $erp->id))->toThrow(Exception::class);
    }
});

test('running the Corporativo demo again adds nothing', function () {
    $count = fn (): array => [DB::table('companies')->count(), DB::table('journal_entries')->count(), DB::table('sales_invoices')->count(), DB::table('company_period_reviews')->count(),
        DB::table('client_requests')->count(), DB::table('work_tasks')->count(), DB::table('organization_user')->count(), DB::table('company_groups')->count(),
        DB::table('api_tokens')->count(), DB::table('webhook_endpoints')->count(), DB::table('training_courses')->count(), DB::table('workspace_roles')->count()];
    $before = $count();

    $this->seed(PlanCorporativoDemoSeeder::class);

    expect($count())->toBe($before);
});
