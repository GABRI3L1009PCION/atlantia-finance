<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\FiscalConfigurationService;
use App\Services\Tenancy\TenantDatabaseContext;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(8, 0));
    unset($GLOBALS['banguatFake']);
    registerBanguatFake();
});

/**
 * What the fake Banguat answers from now on: the daily rates per currency code (euro = 24) and the HTTP status.
 *
 * @param  array<string, array<string, string>>  $rates  currency code => [d/m/Y => venta]
 */
function fakeBanguat(array $rates, int $status = 200): void
{
    $GLOBALS['banguatFake'] = ['rates' => $rates, 'status' => $status];
}

/** Banguat's SOAP answers, registered once per test and driven by fakeBanguat(). */
function registerBanguatFake(): void
{
    Http::fake(['www.banguat.gob.gt/*' => function (Request $request) {
        ['rates' => $rates, 'status' => $status] = $GLOBALS['banguatFake'] ?? ['rates' => [], 'status' => 200];
        $soap = fn (string $method, string $body): string => '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<'.$method.'Response xmlns="http://www.banguat.gob.gt/variables/ws/"><'.$method.'Result>'.$body.'</'.$method.'Result></'.$method.'Response></soap:Body></soap:Envelope>';
        if (str_contains($request->body(), '<VariablesDisponibles')) {
            return Http::response($soap('VariablesDisponibles', '<Variables><Variable><moneda>2</moneda><descripcion>Dólares de EE.UU.</descripcion></Variable>'
                .'<Variable><moneda>24</moneda><descripcion>Euro</descripcion></Variable></Variables>'), $status);
        }
        preg_match('#<moneda>(\d+)</moneda>#', $request->body(), $code);
        $vars = '';
        foreach ($rates[$code[1]] ?? [] as $date => $value) {
            $vars .= '<Var><moneda>'.$code[1].'</moneda><fecha>'.$date.'</fecha><venta>'.$value.'</venta><compra>'.$value.'</compra></Var>';
        }

        return Http::response($soap('TipoCambioRangoMoneda', '<Vars>'.$vars.'</Vars>'), $status);
    }]);
}

/** @return array{owner: User, organization: Organization, uuid: string, companyId: int} */
function banguatCompany(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Cambio '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'professional'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Importadora, S.A.', 'tax_id' => Str::upper(Str::random(8)),
        'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);

    return compact('owner', 'organization', 'uuid', 'companyId');
}

test('the scheduled sync stores the official dollar and euro rates once and only rewrites a corrected one', function () {
    fakeBanguat(['2' => ['24/10/2026' => '7.71234', '25/10/2026' => '7.71234', '26/10/2026' => '7.70011'], '24' => ['26/10/2026' => '8.95510']]);

    $this->artisan('exchange-rates:sync')->expectsOutputToContain('USD: 3')->expectsOutputToContain('EUR: 1')->assertSuccessful();
    $this->artisan('exchange-rates:sync')->expectsOutputToContain('USD: 0')->assertSuccessful();

    $rates = DB::table('reference_exchange_rates')->orderBy('currency')->orderBy('effective_on')->get(['currency', 'rate', 'effective_on', 'source']);
    expect($rates)->toHaveCount(4)
        ->and($rates->pluck('source')->unique()->all())->toBe(['BANGUAT'])
        ->and((string) $rates->firstWhere('currency', 'EUR')->rate)->toBe('8.95510000')
        ->and((string) $rates->where('currency', 'USD')->last()->rate)->toBe('7.70011000');

    // Banguat corrects a published rate: only that day changes.
    fakeBanguat(['2' => ['26/10/2026' => '7.70500'], '24' => ['26/10/2026' => '8.95510']]);
    $this->artisan('exchange-rates:sync')->expectsOutputToContain('USD: 1')->expectsOutputToContain('EUR: 0')->assertSuccessful();
    expect((string) DB::table('reference_exchange_rates')->where('currency', 'USD')->where('effective_on', '2026-10-26')->value('rate'))->toBe('7.70500000');
});

test('a rate outside a sane range or a failing Banguat never stores anything and never breaks the other currency', function () {
    fakeBanguat(['2' => ['26/10/2026' => '770.0'], '24' => ['26/10/2026' => '8.95']]);
    $this->artisan('exchange-rates:sync')->expectsOutputToContain('USD: Banguat no devolvió tipos de cambio válidos')->assertFailed();
    expect(DB::table('reference_exchange_rates')->pluck('currency')->all())->toBe(['EUR']);

    fakeBanguat([], 500);
    $this->artisan('exchange-rates:sync')->assertFailed();
    expect(DB::table('reference_exchange_rates')->count())->toBe(1);
});

test('a foreign currency sale uses the official rate, and a rate recorded by the company wins on the same day', function () {
    $f = banguatCompany();
    $fiscal = app(FiscalConfigurationService::class);
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('reference_exchange_rates')->insert([
        ['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.70000000', 'source' => 'BANGUAT', 'effective_on' => '2026-10-20', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.75000000', 'source' => 'BANGUAT', 'effective_on' => '2026-10-25', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]));
    DB::table('company_exchange_rates')->insert(['company_id' => $f['companyId'], 'currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.80000000', 'source' => 'MANUAL',
        'effective_on' => '2026-10-22', 'created_at' => now(), 'updated_at' => now()]);

    expect((string) $fiscal->exchangeRate($f['companyId'], 'USD', '2026-10-21')->rate)->toBe('7.70000000')
        ->and((string) $fiscal->exchangeRate($f['companyId'], 'USD', '2026-10-23')->rate)->toBe('7.80000000')
        ->and((string) $fiscal->exchangeRate($f['companyId'], 'USD', '2026-10-26')->rate)->toBe('7.75000000')
        ->and($fiscal->convertToQuetzales($f['companyId'], 10000, 'USD', '2026-10-26'))->toBe(77500);

    DB::table('company_exchange_rates')->insert(['company_id' => $f['companyId'], 'currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.79000000', 'source' => 'MANUAL',
        'effective_on' => '2026-10-25', 'created_at' => now(), 'updated_at' => now()]);
    expect((string) $fiscal->exchangeRate($f['companyId'], 'USD', '2026-10-26')->rate)->toBe('7.79000000');

    expect(fn () => $fiscal->exchangeRate($f['companyId'], 'EUR', '2026-10-26'))->toThrow(ValidationException::class);
});

test('every firm reads the official rates but only the platform can write them', function () {
    $f = banguatCompany();
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('reference_exchange_rates')->insert(['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.70000000',
        'source' => 'BANGUAT', 'effective_on' => '2026-10-26', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]));

    app(TenantDatabaseContext::class)->useOrganization($f['organization']->id, $f['owner']->id);
    expect(DB::table('reference_exchange_rates')->count())->toBe(1)
        ->and(fn () => DB::transaction(fn () => DB::table('reference_exchange_rates')->insert(['currency' => 'EUR', 'quote_currency' => 'GTQ', 'rate' => '9',
            'source' => 'BANGUAT', 'effective_on' => '2026-10-26', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('reference_exchange_rates')->update(['rate' => '1'])))->not->toThrow(QueryException::class);
    app(TenantDatabaseContext::class)->useSystem();
    expect((string) DB::table('reference_exchange_rates')->value('rate'))->toBe('7.70000000');
});

test('the fiscal settings list the official rates and the owner can refresh them from Banguat', function () {
    $f = banguatCompany();
    fakeBanguat(['2' => ['26/10/2026' => '7.70011'], '24' => ['26/10/2026' => '8.95510']]);

    $this->actingAs($f['owner'])->post(route('accounting.fiscalExchangeRateSync', $f['uuid']))->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('accounting_notice', 'Tipos de cambio oficiales de Banguat actualizados.');
    $this->get(route('accounting.fiscalSettings', $f['uuid']))->assertInertia(fn ($page) => $page
        ->has('exchangeRates', 2)->where('exchangeRates.0.origin', 'official')->where('exchangeRates.0.source', 'BANGUAT'));

    fakeBanguat([], 503);
    $this->post(route('accounting.fiscalExchangeRateSync', $f['uuid']))->assertSessionHasErrors('exchange_rate');
});
