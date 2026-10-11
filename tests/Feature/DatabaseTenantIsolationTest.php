<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an organization with one company, two accounts and an open August 2026 period, as the platform.
 *
 * @return array{organization: Organization, owner: User, officeId: int, companyId: int, cash: int, capital: int, periodId: int}
 */
function isolatedTenant(string $name): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => $name, 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $officeId = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true])->id;
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $officeId, 'name' => $name.' SA', 'tax_id' => Str::random(8)]);
    $cash = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '1101', 'name' => 'Caja', 'type' => 'asset']);
    $capital = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '3101', 'name' => 'Capital', 'type' => 'equity']);
    $periodId = DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => 'Agosto 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);

    return compact('organization', 'owner', 'officeId', 'companyId', 'cash', 'capital', 'periodId');
}

/** Inserts a draft entry with the given lines (debit, credit) against the tenant's two accounts. */
function isolatedDraftEntry(array $tenant, int $debitCents, int $creditCents): int
{
    $entryId = DB::table('journal_entries')->insertGetId([
        'public_id' => (string) Str::uuid(), 'accounting_period_id' => $tenant['periodId'], 'entry_date' => '2026-08-10',
        'description' => 'Aporte', 'status' => 'draft', 'created_by' => $tenant['owner']->id,
    ]);
    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $tenant['cash'], 'position' => 1, 'debit_cents' => $debitCents, 'credit_cents' => 0],
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $tenant['capital'], 'position' => 2, 'debit_cents' => 0, 'credit_cents' => $creditCents],
    ]);

    return $entryId;
}

/** Runs a write inside a savepoint so an expected database rejection does not abort the test transaction. */
function inSavepoint(callable $write): Closure
{
    return fn () => DB::transaction($write);
}

function postIsolatedEntry(int $entryId, int $userId): void
{
    DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'posted', 'number' => 1, 'posted_by' => $userId, 'posted_at' => now()]);
}

test('organization_id is derived from the parent row on every tenant table', function () {
    $tenant = isolatedTenant('Firma A');
    $organizationId = $tenant['organization']->id;

    expect(DB::table('companies')->where('id', $tenant['companyId'])->value('organization_id'))->toBe($organizationId)
        ->and(DB::table('ledger_accounts')->where('id', $tenant['cash'])->value('organization_id'))->toBe($organizationId)
        ->and(DB::table('accounting_periods')->where('id', $tenant['periodId'])->value('organization_id'))->toBe($organizationId)
        ->and(DB::table('journal_lines')->where('journal_entry_id', isolatedDraftEntry($tenant, 100, 100))->pluck('organization_id')->unique()->all())->toBe([$organizationId]);
});

test('a tenant session only sees its own organization and fails closed without a tenant', function () {
    $first = isolatedTenant('Firma A');
    $second = isolatedTenant('Firma B');
    $context = app(TenantDatabaseContext::class);

    $context->useOrganization($first['organization']->id, $first['owner']->id);
    expect(DB::table('companies')->pluck('id')->all())->toBe([$first['companyId']])
        ->and(DB::table('organizations')->pluck('id')->all())->toBe([$first['organization']->id])
        ->and(DB::table('ledger_accounts')->where('company_id', $second['companyId'])->count())->toBe(0);

    $context->clear();
    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('organizations')->count())->toBe(0)
        ->and(DB::table('journal_entries')->count())->toBe(0);

    $context->useSystem();
    expect(DB::table('companies')->count())->toBe(2);
});

test('a tenant session cannot write rows for another organization', function () {
    $first = isolatedTenant('Firma A');
    $second = isolatedTenant('Firma B');
    app(TenantDatabaseContext::class)->useOrganization($first['organization']->id, $first['owner']->id);

    expect(inSavepoint(fn () => DB::table('ledger_accounts')->insert(['company_id' => $second['companyId'], 'code' => '9999', 'name' => 'Intrusa', 'type' => 'asset'])))
        ->toThrow(QueryException::class, 'not visible to this organization');

    expect(inSavepoint(fn () => DB::table('ledger_accounts')->insert(['organization_id' => $second['organization']->id, 'company_id' => $first['companyId'], 'code' => '9998', 'name' => 'Intrusa', 'type' => 'asset'])))
        ->toThrow(QueryException::class, 'Cross-organization reference rejected');

    expect(DB::table('companies')->where('id', $second['companyId'])->update(['name' => 'Secuestrada']))->toBe(0);
});

test('rows can never point at records owned by another organization even with platform access', function () {
    $first = isolatedTenant('Firma A');
    $second = isolatedTenant('Firma B');
    $entryId = isolatedDraftEntry($first, 500, 500);

    expect(inSavepoint(fn () => DB::table('journal_lines')->insert([
        'journal_entry_id' => $entryId, 'ledger_account_id' => $second['cash'], 'position' => 3, 'debit_cents' => 1, 'credit_cents' => 0,
    ])))->toThrow(QueryException::class, 'Cross-organization reference rejected');

    expect(inSavepoint(fn () => DB::table('companies')->where('id', $first['companyId'])->update(['organization_id' => $second['organization']->id])))
        ->toThrow(QueryException::class, 'organization_id is immutable');
});

test('only balanced entries with at least two lines can be posted', function () {
    $tenant = isolatedTenant('Firma A');
    $unbalanced = isolatedDraftEntry($tenant, 1000, 900);

    expect(inSavepoint(fn () => postIsolatedEntry($unbalanced, $tenant['owner']->id)))->toThrow(QueryException::class, 'is not balanced');

    $balanced = isolatedDraftEntry($tenant, 1000, 1000);
    postIsolatedEntry($balanced, $tenant['owner']->id);

    expect(DB::table('journal_entries')->where('id', $balanced)->value('status'))->toBe('posted');
});

test('posted entries and their lines are immutable except for the reversal transition', function () {
    $tenant = isolatedTenant('Firma A');
    $entryId = isolatedDraftEntry($tenant, 1000, 1000);
    postIsolatedEntry($entryId, $tenant['owner']->id);

    expect(inSavepoint(fn () => DB::table('journal_entries')->where('id', $entryId)->update(['description' => 'Maquillado'])))
        ->toThrow(QueryException::class, 'Posted journal entries are immutable');
    expect(inSavepoint(fn () => DB::table('journal_lines')->where('journal_entry_id', $entryId)->update(['debit_cents' => 5])))
        ->toThrow(QueryException::class, 'immutable');
    expect(inSavepoint(fn () => DB::table('journal_lines')->where('journal_entry_id', $entryId)->delete()))
        ->toThrow(QueryException::class, 'immutable');
    expect(inSavepoint(fn () => DB::table('journal_entries')->where('id', $entryId)->delete()))
        ->toThrow(QueryException::class, 'Only draft journal entries can be deleted');

    DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'reversed', 'updated_at' => now()]);

    expect(inSavepoint(fn () => DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'posted'])))
        ->toThrow(QueryException::class, 'is final');
});

test('closed periods and dates outside the period reject journal entries', function () {
    $tenant = isolatedTenant('Firma A');

    expect(inSavepoint(fn () => DB::table('journal_entries')->insert([
        'public_id' => (string) Str::uuid(), 'accounting_period_id' => $tenant['periodId'], 'entry_date' => '2026-09-15',
        'description' => 'Fuera de fecha', 'status' => 'draft', 'created_by' => $tenant['owner']->id,
    ])))->toThrow(QueryException::class, 'does not accept entries');

    $draft = isolatedDraftEntry($tenant, 300, 300);
    DB::table('accounting_periods')->where('id', $tenant['periodId'])->update(['status' => 'closed', 'closed_at' => now()]);

    expect(inSavepoint(fn () => isolatedDraftEntry($tenant, 100, 100)))->toThrow(QueryException::class, 'does not accept entries');
    expect(inSavepoint(fn () => postIsolatedEntry($draft, $tenant['owner']->id)))->toThrow(QueryException::class, 'does not accept entries');
});

test('entries must be created as drafts', function () {
    $tenant = isolatedTenant('Firma A');

    expect(inSavepoint(fn () => DB::table('journal_entries')->insert([
        'public_id' => (string) Str::uuid(), 'accounting_period_id' => $tenant['periodId'], 'entry_date' => '2026-08-10',
        'description' => 'Directo', 'status' => 'posted', 'number' => 1, 'created_by' => $tenant['owner']->id,
        'posted_by' => $tenant['owner']->id, 'posted_at' => now(),
    ])))->toThrow(QueryException::class, 'must be created as drafts');
});

test('the audit log is append-only', function () {
    $tenant = isolatedTenant('Firma A');
    $auditId = DB::table('audit_logs')->insertGetId([
        'organization_id' => $tenant['organization']->id, 'action' => 'test.recorded', 'occurred_at' => now(),
    ]);

    expect(inSavepoint(fn () => DB::table('audit_logs')->where('id', $auditId)->update(['action' => 'test.tampered'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(inSavepoint(fn () => DB::table('audit_logs')->where('id', $auditId)->delete()))
        ->toThrow(QueryException::class, 'append-only');
});

test('platform tables are invisible to tenant sessions', function () {
    $tenant = isolatedTenant('Firma A');
    DB::table('webhook_events')->insert([
        'provider' => 'recurrente', 'provider_event_id' => 'evt_1', 'event_type' => 'payment.succeeded',
        'payload' => '{}', 'received_at' => now(),
    ]);

    app(TenantDatabaseContext::class)->useOrganization($tenant['organization']->id, $tenant['owner']->id);

    expect(DB::table('webhook_events')->count())->toBe(0);
});

test('http requests run inside the authenticated user tenant scope and restore the previous scope', function () {
    $first = isolatedTenant('Firma A');
    isolatedTenant('Firma B');
    $context = app(TenantDatabaseContext::class);

    $this->actingAs($first['owner'])->get(route('billing.plans.index'));

    expect($context->state()['system'])->toBeTrue()
        ->and(DB::table('companies')->count())->toBe(2);
});
