<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Luzma\LuzmaContext;
use App\Services\Luzma\LuzmaFinanceTools;
use App\Services\Luzma\LuzmaPeriod;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/Guatemala'));
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['luzma.gateway.down', 'luzma.watchdog.restart', 'luzma.last_provider'] as $key) {
        Cache::forget($key);
    }
    Http::fake([
        '*/ai/luzma/plan' => Http::response(['intent' => 'general', 'tool' => '', 'arguments' => [], 'confidence' => 0]),
        '*/ai/luzma/answer' => Http::response(['status' => 'ok', 'model' => 'qwen3', 'response' => 'Respuesta de Luzma.']),
    ]);
});

/**
 * A company with two months of activity: September (sales Q 11,200, revenue Q 10,000, rent Q 3,000) and October up to
 * the 20th (sales Q 16,800, revenue Q 15,000, rent Q 3,000 and new professional fees Q 4,000).
 *
 * @return array{org: Organization, owner: User, companyId: int, uuid: string}
 */
function financeFixture(): array
{
    $org = Organization::create(['name' => 'Firma Finanzas', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'FIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Quetzal', 'tax_id' => '1234567-8', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingService::class)->installDefaultChart($companyId);
    DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $start) {
        $month = CarbonImmutable::parse($start);
        DB::table('accounting_periods')->insert(['company_id' => $companyId, 'name' => LuzmaPeriod::monthLabel($month), 'starts_on' => $start, 'ends_on' => $month->endOfMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }
    $f = compact('org', 'owner', 'companyId', 'uuid');
    financeEntry($f, '2026-09-15', [['1101', 1000000, 0], ['4201', 0, 1000000]]);
    financeEntry($f, '2026-09-15', [['5203', 300000, 0], ['1101', 0, 300000]]);
    financeEntry($f, '2026-10-10', [['1101', 1500000, 0], ['4201', 0, 1500000]]);
    financeEntry($f, '2026-10-12', [['5203', 300000, 0], ['5208', 400000, 0], ['1101', 0, 700000]]);
    financeSale($f, 'Cliente Alfa', '2026-09-10', 1120000, 120000);
    financeSale($f, 'Cliente Alfa', '2026-10-05', 560000, 60000);
    financeSale($f, 'Cliente Beta', '2026-10-15', 1120000, 120000);
    financeSale($f, 'Cliente Beta', '2026-10-16', 224000, 24000, 'void');
    financePurchase($f, 'Proveedor Uno', '2026-10-08', 224000, 24000);
    financePurchase($f, 'Proveedor Dos', '2026-10-09', 50000, 0, 'draft');

    return $f;
}

/**
 * @param  array<string, mixed>  $f
 * @param  list<array{0: string, 1: int, 2: int}>  $lines  account code, debit, credit
 */
function financeEntry(array $f, string $date, array $lines): void
{
    $period = DB::table('accounting_periods')->where('company_id', $f['companyId'])->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->value('id');
    $entry = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'accounting_period_id' => $period, 'entry_date' => $date, 'description' => 'Movimiento', 'kind' => 'standard',
        'status' => 'draft', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($lines as $position => [$code, $debit, $credit]) {
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'ledger_account_id' => DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', $code)->value('id'),
            'position' => $position + 1, 'debit_cents' => $debit, 'credit_cents' => $credit]);
    }
    DB::table('journal_entries')->where('id', $entry)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $f['owner']->id, 'posted_at' => now()]);
}

/** @param array<string, mixed> $f */
function financeSale(array $f, string $customer, string $date, int $total, int $vat, string $status = 'issued'): void
{
    $customerId = DB::table('sales_customers')->where('company_id', $f['companyId'])->where('name', $customer)->value('id')
        ?? DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => $customer, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $customerId, 'number' => (int) DB::table('sales_invoices')->where('company_id', $f['companyId'])->max('number') + 1,
        'status' => $status, 'issued_on' => $date, 'due_on' => CarbonImmutable::parse($date)->addDays(30)->toDateString(), 'total_cents' => $total, 'vat_cents' => $vat, 'taxable_goods_cents' => $total - $vat,
        'customer_name' => $customer, 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

/** @param array<string, mixed> $f */
function financePurchase(array $f, string $supplier, string $date, int $total, int $vat, string $status = 'approved'): void
{
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => $supplier, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_documents')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'purchase_supplier_id' => $supplierId, 'number' => (int) DB::table('purchase_documents')->where('company_id', $f['companyId'])->max('number') + 1,
        'document_type' => 'purchase', 'status' => $status, 'document_date' => $date, 'due_on' => CarbonImmutable::parse($date)->addDays(15)->toDateString(), 'total_cents' => $total, 'vat_cents' => $vat,
        'taxable_goods_cents' => $total - $vat, 'supplier_name' => $supplier, 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
}

/** @param array<string, mixed> $f */
function financeContext(array $f): LuzmaContext
{
    return new LuzmaContext($f['org'], $f['owner'], 'owner_director', [$f['companyId']], $f['companyId'], 'Comercial Quetzal', null, null, 'accounting', null, null, null, 'America/Guatemala');
}

/** @param array<string, mixed> $f */
function financeAsk(array $f, string $message, ?string $conversation = null): TestResponse
{
    return test()->actingAs($f['owner'])->postJson(route('luzma.chat'), array_filter(['message' => $message, 'company' => $f['uuid'], 'module' => 'accounting', 'conversation_id' => $conversation]))->assertOk();
}

test('the period of a question is read from its words and compared with the right previous period', function () {
    $tz = 'America/Guatemala';
    $month = LuzmaPeriod::fromText('¿cuánto vendí este mes?', $tz);
    expect([$month->from, $month->to, $month->previousFrom, $month->previousTo, $month->label])->toBe(['2026-10-01', '2026-10-20', '2026-09-01', '2026-09-20', 'octubre 2026']);
    expect(LuzmaPeriod::fromText('ventas de septiembre', $tz)->current())->toBe(['from' => '2026-09-01', 'to' => '2026-09-30', 'label' => 'septiembre 2026'])
        ->and(LuzmaPeriod::fromText('ventas de noviembre', $tz)->from)->toBe('2025-11-01')
        ->and(LuzmaPeriod::fromText('el mes pasado', $tz)->label)->toBe('septiembre 2026')
        ->and(LuzmaPeriod::fromText('en lo que va del año', $tz)->current())->toBe(['from' => '2026-01-01', 'to' => '2026-10-20', 'label' => 'año 2026 hasta el 20/10'])
        ->and(LuzmaPeriod::fromText('el año pasado', $tz)->current())->toBe(['from' => '2025-01-01', 'to' => '2025-12-31', 'label' => 'año 2025'])
        ->and(LuzmaPeriod::fromText('el primer trimestre', $tz)->current())->toBe(['from' => '2026-01-01', 'to' => '2026-03-31', 'label' => 'trimestre 1 de 2026'])
        ->and(count(LuzmaPeriod::fromText('los últimos 6 meses', $tz)->months()))->toBe(6)
        ->and(LuzmaPeriod::fromText('marzo 2025', $tz)->from)->toBe('2025-03-01');
});

test('sales, purchases and the VAT of the month are read from the registered documents', function () {
    $f = financeFixture();
    $tools = app(LuzmaFinanceTools::class);

    $sales = $tools->sales(financeContext($f), ['period' => '¿cuánto vendimos este mes?']);
    expect($sales['facts'][0])->toBe('Comercial Quetzal · ventas de octubre 2026: Q 16,800.00 en 2 factura(s), IVA débito Q 1,800.00.')
        ->and($sales['facts'][1])->toContain('Contra septiembre 2026: +50.0 % (Q 5,600.00 más)')
        ->and($sales['facts'][2])->toBe('1 factura(s) anulada(s) en el período.')
        ->and($sales['data']['table']['rows'][0])->toBe(['Cliente Beta', '1', 'Q 11,200.00', '66.7 %']);

    $purchases = $tools->purchases(financeContext($f), ['period' => 'compras de octubre']);
    expect($purchases['facts'][0])->toContain('Q 2,240.00 en 1 documento(s), IVA crédito Q 240.00')
        ->and(implode(' ', $purchases['facts']))->toContain('1 compra(s) del período siguen en borrador');

    $vat = $tools->vat(financeContext($f), ['period' => 'iva del mes']);
    expect(implode(' ', $vat['facts']))->toContain('IVA de Octubre 2026')->toContain('vence el 30/11/2026')->toContain('El mes sigue en curso');
});

test('the statements, the variance, the trend and the projections come from the posted entries', function () {
    $f = financeFixture();
    $tools = app(LuzmaFinanceTools::class);
    $c = financeContext($f);

    $income = $tools->incomeStatement($c, ['period' => 'estado de resultados del año']);
    expect($income['facts'][0])->toContain('ingresos Q 25,000.00')->toContain('gastos de operación Q 10,000.00')->toContain('utilidad Q 15,000.00')
        ->and($income['data']['table']['rows'][0][0])->toBe('Ingresos');

    $variance = $tools->profitVariance($c, ['period' => '¿por qué cambió la utilidad este mes?']);
    expect($variance['facts'][0])->toBe('Comercial Quetzal · resultado de octubre 2026 Q 8,000.00 contra Q 7,000.00 de septiembre 2026: mejoró Q 1,000.00.')
        ->and(implode(' ', $variance['facts']))->toContain('Ingreso Ventas: Q 10,000.00 → Q 15,000.00, sumó Q 5,000.00 al resultado.')
        ->toContain('Gasto Honorarios profesionales: Q 0.00 → Q 4,000.00, restó Q 4,000.00 al resultado.');

    $trends = $tools->trends($c, []);
    expect($trends['data']['table']['rows'])->toHaveCount(6)
        ->and($trends['data']['table']['rows'][5])->toBe(['octubre 2026', 'Q 15,000.00', 'Q 7,000.00', 'Q 8,000.00', '53.3 %']);

    $balance = $tools->balanceSheet($c, []);
    expect($balance['facts'][0])->toContain('activos Q 15,000.00');

    $projection = $tools->cashProjection($c);
    expect($projection['facts'][0])->toBe('Comercial Quetzal · efectivo hoy (caja y bancos según el Mayor): Q 15,000.00.')
        ->and($projection['data']['table']['rows'])->toHaveCount(3);

    $breakEven = $tools->breakEven($c, []);
    expect($breakEven['facts'][1])->toContain('Punto de equilibrio: vender Q 10,000.00');

    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('reference_exchange_rates')->insert([
        ['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.70000000', 'source' => 'BANGUAT', 'effective_on' => '2026-09-18', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.65000000', 'source' => 'BANGUAT', 'effective_on' => '2026-10-20', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]));
    expect($tools->exchangeRate($c)['facts'][0])->toBe('Tipo de cambio oficial de Banguat del dólar al 20/10/2026: Q 7.65000 por USD (hace 30 días Q 7.70000, −0.65 %).');
});

test('each question reaches its tool, several questions are answered together and a follow-up keeps or changes the period', function () {
    $f = financeFixture();

    foreach ([
        '¿Cuánto vendimos en septiembre?' => 'get_sales_summary', '¿Cuánto IVA me toca pagar este mes?' => 'get_vat_summary', '¿Por qué bajó la utilidad?' => 'get_profit_variance',
        '¿A cuánto está el dólar hoy?' => 'get_exchange_rate', '¿Cómo se prepara el estado de resultados?' => 'search_normative', 'Dame el estado de resultados del año' => 'get_income_statement',
        '¿Cuál es el punto de equilibrio?' => 'get_break_even', '¿Nos va a alcanzar el dinero? Dame la proyección de caja' => 'get_cash_projection', '¿Cuáles son mis mejores clientes?' => 'get_top_rankings',
        'balanza de comprobación de octubre' => 'get_trial_balance', '¿Cuáles son los próximos impuestos?' => 'get_tax_projection',
    ] as $question => $tool) {
        financeAsk($f, $question)->assertJsonPath('message.tools.0', $tool);
    }

    $many = financeAsk($f, '¿Cuánto vendí en octubre y cuánto IVA pago?')->assertJsonPath('message.tools.0', 'get_sales_summary+get_vat_summary');
    expect($many->json('message.tables.0.title'))->toBe('Principales clientes de octubre 2026');
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/answer')
        && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'ventas de octubre 2026: Q 16,800.00') && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'IVA de Octubre 2026'));

    $first = financeAsk($f, '¿Cuánto vendimos en septiembre?');
    $conversation = $first->json('conversation.id');
    financeAsk($f, 'dame más detalle', $conversation)->assertJsonPath('message.tools.0', 'get_sales_summary')->assertJsonPath('message.tables.0.title', 'Principales clientes de septiembre 2026');
    financeAsk($f, '¿y en octubre?', $conversation)->assertJsonPath('message.tools.0', 'get_sales_summary')->assertJsonPath('message.tables.0.title', 'Principales clientes de octubre 2026');

    // The tables stay with the conversation.
    $this->getJson(route('luzma.conversation', $conversation))->assertOk()->assertJsonPath('messages.5.tables.0.title', 'Principales clientes de octubre 2026');
});
