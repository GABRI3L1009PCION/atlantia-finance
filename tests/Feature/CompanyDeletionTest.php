<?php

use App\Mail\CompanyDeletionMail;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\CompanyDeletionService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
    Storage::fake('local');
    Mail::fake();
});

test('the owner sends a company to the trash after a backup, everything hides it and it can be restored', function () {
    $f = deletionFirm();

    $this->actingAs($f['owner'])->getJson(route('office.companies.deletion', $f['company']))->assertOk()
        ->assertJson(['name' => 'Finca Las Margaritas', 'sales_invoices' => 0, 'trash_days' => 30]);
    $this->delete(route('office.companies.destroy', $f['company']), ['reason' => 'created_by_mistake', 'note' => 'Era un cliente de El Progreso', 'confirm_name' => 'finca las margaritas', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $deletion = DB::table('company_deletions')->first();
    expect($deletion->reason)->toBe('created_by_mistake')->and($deletion->deleted_by)->toBe($f['owner']->id);
    Storage::disk('local')->assertExists($deletion->backup_path);
    $backup = collect(explode("\n", trim(gzdecode(Storage::disk('local')->get($deletion->backup_path)))))->map(fn (string $line) => json_decode($line, true))->keyBy('table');
    expect($backup['companies']['rows'][0]['name'])->toBe('Finca Las Margaritas')
        ->and($backup['sales_customers']['rows'][0]['name'])->toBe('Cliente de la Finca')
        ->and(hash('sha256', Storage::disk('local')->get($deletion->backup_path)))->toBe($deletion->backup_sha256);

    // Hidden by the database itself, in the company list and in the tables that hang from it.
    expect(DB::table('companies')->where('id', $f['company'])->exists())->toBeFalse()
        ->and(DB::table('sales_customers')->where('company_id', $f['company'])->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $f['other'])->exists())->toBeTrue();
    $this->get(route('office.admin.companies'))->assertInertia(fn (Assert $page) => $page
        ->where('companies', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Finca Las Margaritas'))
        ->where('trash.0.name', 'Finca Las Margaritas')->where('trash.0.days_left', 30)->where('canDeleteCompanies', true));
    Mail::assertSent(CompanyDeletionMail::class, fn (CompanyDeletionMail $mail) => $mail->hasTo($f['owner']->email) && str_contains($mail->title, 'eliminó la empresa Finca Las Margaritas'));
    expect(DB::table('audit_logs')->where('action', 'company.deleted')->exists())->toBeTrue();

    // A company in the trash cannot be registered again by mistake: it is restored instead.
    expect(fn () => app(CompanyAdministrationService::class)->create($f['org'], $f['owner'], ['name' => 'Otra Finca', 'tax_id' => 'FINCA-1', 'office_id' => $f['office']->id]))
        ->toThrow(ValidationException::class);

    $this->post(route('office.companyDeletions.restore', $deletion->id))->assertSessionHasNoErrors();
    expect(DB::table('companies')->where('id', $f['company'])->exists())->toBeTrue()
        ->and(DB::table('sales_customers')->where('company_id', $f['company'])->exists())->toBeTrue();
});

test('only the owner deletes, and only with the exact name and the right password', function () {
    $f = deletionFirm();
    $admin = User::factory()->create();
    $f['org']->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->delete(route('office.companies.destroy', $f['company']), ['reason' => 'duplicate', 'confirm_name' => 'Finca Las Margaritas', 'password' => 'password'])->assertForbidden();
    $this->actingAs($f['owner'])->delete(route('office.companies.destroy', $f['company']), ['reason' => 'duplicate', 'confirm_name' => 'Otra', 'password' => 'password'])->assertSessionHasErrors('confirm_name');
    $this->delete(route('office.companies.destroy', $f['company']), ['reason' => 'duplicate', 'confirm_name' => 'Finca Las Margaritas', 'password' => 'incorrecta'])->assertSessionHasErrors('password');
    $this->delete(route('office.companies.destroy', $f['company']), ['reason' => 'other', 'confirm_name' => 'Finca Las Margaritas', 'password' => 'password'])->assertSessionHasErrors('note');

    expect(DB::table('company_deletions')->count())->toBe(0)->and(DB::table('companies')->where('id', $f['company'])->exists())->toBeTrue();
});

test('owners are reminded a week and a day before, then the company is purged and its NIT is free again', function () {
    $f = deletionFirm();
    $id = app(CompanyDeletionService::class)->delete($f['org'], $f['owner'], $f['company'], 'contract_ended', null, '127.0.0.1');
    $service = app(CompanyDeletionService::class);

    expect($service->sweep(now()->addDays(10)))->toBe(['reminded' => 0, 'purged' => 0])
        ->and($service->sweep(now()->addDays(23)->addHour()))->toBe(['reminded' => 1, 'purged' => 0])
        ->and($service->sweep(now()->addDays(24)))->toBe(['reminded' => 0, 'purged' => 0])
        ->and($service->sweep(now()->addDays(29)->addHour()))->toBe(['reminded' => 1, 'purged' => 0])
        ->and($service->sweep(now()->addDays(30)->addHour()))->toBe(['reminded' => 0, 'purged' => 1]);
    Mail::assertSent(CompanyDeletionMail::class, fn (CompanyDeletionMail $mail) => str_contains($mail->title, 'se eliminará definitivamente en 7 días'));
    Mail::assertSent(CompanyDeletionMail::class, fn (CompanyDeletionMail $mail) => str_contains($mail->title, 'se eliminará definitivamente mañana'));

    expect(fn () => $service->restore($f['org'], $f['owner'], $id))->toThrow(ValidationException::class)
        ->and($service->trash($f['org']))->toBe([]);
    $again = app(CompanyAdministrationService::class)->create($f['org'], $f['owner'], ['name' => 'Finca Las Margaritas', 'tax_id' => 'FINCA-1', 'office_id' => $f['office']->id]);
    expect(DB::table('companies')->where('public_id', $again['public_id'])->value('tax_id'))->toBe('FINCA-1');
});

test('every tenant table that holds company rows hides the companies in the trash', function () {
    $tables = collect(DB::select("SELECT c.table_name FROM information_schema.columns c JOIN pg_class k ON k.relname = c.table_name AND k.relkind = 'r' AND k.relrowsecurity
        WHERE c.table_schema = current_schema() AND c.column_name = 'company_id' AND c.table_name <> 'company_deletions'"))->pluck('table_name');
    $protected = collect(DB::select("SELECT tablename FROM pg_policies WHERE policyname = 'hide_deleted_companies'"))->pluck('tablename');

    expect($tables->diff($protected)->values()->all())->toBe([])->and($protected)->toContain('companies');
});

/** @return array{org: Organization, owner: User, office: mixed, company: int, other: int} */
function deletionFirm(): array
{
    $org = Organization::create(['name' => 'Hermanos Demo', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Hermanos Demo', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $office->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $company = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Finca Las Margaritas', 'tax_id' => 'FINCA-1', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $other = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'El Progreso', 'tax_id' => 'PROG-1', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_customers')->insert(['company_id' => $company, 'name' => 'Cliente de la Finca', 'tax_id' => 'CF', 'created_at' => now(), 'updated_at' => now()]);

    return compact('org', 'owner', 'office', 'company', 'other');
}
