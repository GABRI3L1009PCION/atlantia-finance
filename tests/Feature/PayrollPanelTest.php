<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\AccountingAutomationService;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\Fiscal\OtherTaxesService;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaToolRegistry;
use App\Services\Payroll\PayrollLedgerReconciliationService;
use App\Services\Payroll\SalaryBookService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PayrollParameterSeeder;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->seed(PayrollParameterSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(10, 0));
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('payroll lands on its panel, only sees assigned companies and cannot approve its own payroll', function () {
    $f = payrollFixture();
    payrollEmployee($f, 600000);
    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($f['org'], $f['payroll']))->toBe('/payroll/dashboard')
        ->and($authorization->can($f['org'], $f['payroll'], 'payroll.approve'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['payroll'], 'treasury.payments.execute'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['accountant'], 'payroll.approve'))->toBeTrue();

    $this->actingAs($f['payroll'])->get(route('payroll.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/dashboard')
            ->where('panel.metrics.assigned_companies', 1)
            ->where('panel.metrics.active_employees', 1)
            ->has('panel.companies', 1)
            ->where('panel.companies.0.status', 'no_period')
            ->where('panel.companies.0.net_cents', null));

    $this->get(route('payroll.company', $f['otherCompanyId']))->assertForbidden();
    $this->actingAs($f['treasury'])->get(route('payroll.dashboard'))->assertForbidden();

    // Luzma reads the same payroll service, only for the companies of the user.
    $context = app(LuzmaContextService::class)->resolve($f['payroll'], ['module' => 'payroll']);
    $summary = app(LuzmaToolRegistry::class)->run($context, 'get_payroll_summary');
    expect($summary['ok'])->toBeTrue()->and($summary['facts'][0])->toContain('Comercial Maya')->and(implode(' ', $summary['facts']))->not->toContain('Otra Empresa');
    expect(fn () => app(LuzmaToolRegistry::class)->run(app(LuzmaContextService::class)->resolve($f['treasury'], ['module' => 'payroll']), 'get_payroll_summary'))->toThrow(HttpException::class);
});

test('a monthly payroll is calculated from legal parameters, approved by someone else, paid by treasury and posted', function () {
    Storage::fake('local');
    $f = payrollFixture();
    $this->actingAs($f['payroll']);

    $this->post(route('payroll.employees.store'), payrollEmployeeForm($f, ['bank_account_number' => '']))->assertSessionHasErrors('bank_account_number');
    $this->post(route('payroll.employees.store'), payrollEmployeeForm($f))->assertSessionHasNoErrors();
    $employee = (int) DB::table('payroll_employees')->where('company_id', $f['mayaId'])->value('id');

    $this->post(route('payroll.periods.store'), ['company_id' => $f['mayaId'], 'month' => '2026-09'])->assertSessionHasNoErrors();
    $this->post(route('payroll.periods.store'), ['company_id' => $f['mayaId'], 'month' => '2026-09'])->assertSessionHasErrors('month');
    $period = (int) DB::table('payroll_periods')->where('company_id', $f['mayaId'])->value('id');

    $this->post(route('payroll.novelties.store'), ['period_id' => $period, 'payroll_employee_id' => $employee, 'concept_code' => 'HORAS_EXTRA', 'quantity' => 10])->assertSessionHasNoErrors();
    $this->post(route('payroll.novelties.store'), ['period_id' => $period, 'payroll_employee_id' => $employee, 'concept_code' => 'HORAS_EXTRA', 'quantity' => 10])->assertSessionHasErrors('concept_code');
    $novelty = (int) DB::table('payroll_novelties')->where('payroll_period_id', $period)->value('id');

    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();
    $this->post(route('payroll.periods.action', [$period, 'submit']))->assertSessionHasErrors('status');
    $this->post(route('payroll.novelties.action', [$novelty, 'approve']))->assertSessionHasNoErrors();
    expect((bool) DB::table('payroll_periods')->where('id', $period)->value('needs_recalculation'))->toBeTrue();
    $this->post(route('payroll.periods.action', [$period, 'submit']))->assertSessionHasErrors('status');
    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();

    // Q6,000 salary + Q250 incentive + 10 overtime hours (6000/30/8 × 1.5 × 10 = Q375).
    $result = DB::table('payroll_results')->where('payroll_period_id', $period)->first();
    $line = fn (string $code): int => (int) DB::table('payroll_result_lines')->where('payroll_result_id', $result->id)->where('concept_code', $code)->sum('amount_cents');
    expect((int) $result->gross_cents)->toBe(662500)
        ->and($line('IGSS_LABORAL'))->toBe(30791)
        ->and($line('ISR'))->toBe(0)
        ->and((int) $result->net_cents)->toBe(631709)
        ->and($line('IGSS_PATRONAL'))->toBe(68021)
        ->and($line('IRTRA'))->toBe(6375)
        ->and($line('PROV_AGUINALDO'))->toBe(50000)
        ->and($result->status)->toBe('ok');

    $this->post(route('payroll.periods.action', [$period, 'submit']))->assertSessionHasNoErrors();
    $this->post(route('payroll.periods.action', [$period, 'approve']))->assertSessionHasErrors('status');
    $this->post(route('payroll.novelties.store'), ['period_id' => $period, 'payroll_employee_id' => $employee, 'concept_code' => 'BONO', 'amount' => '100'])->assertSessionHasNoErrors();
    expect(DB::table('payroll_periods')->where('id', $period)->value('status'))->toBe('calculated');
    DB::table('payroll_novelties')->where('concept_code', 'BONO')->delete();
    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();
    $this->post(route('payroll.periods.action', [$period, 'submit']))->assertSessionHasNoErrors();

    $this->actingAs($f['accountant'])->get(route('payroll.period', $period))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.review', true)->where('can.send', false));
    $this->post(route('payroll.periods.action', [$period, 'approve']), ['note' => 'Revisada'])->assertSessionHasNoErrors();
    expect(DB::table('payroll_periods')->where('id', $period)->value('status'))->toBe('approved')
        ->and(DB::table('payroll_receipts')->where('payroll_period_id', $period)->count())->toBe(1)
        ->and(DB::table('payroll_payment_orders')->where('payroll_period_id', $period)->value('amount_cents'))->toBe(631709);
    $entry = DB::table('accounting_source_entries')->where('source_type', 'payroll')->where('source_id', $period)->value('journal_entry_id');
    expect(DB::table('journal_lines')->where('journal_entry_id', $entry)->sum('debit_cents'))->toBe(DB::table('journal_lines')->where('journal_entry_id', $entry)->sum('credit_cents'))
        ->and((int) DB::table('journal_lines')->where('journal_entry_id', $entry)->where('ledger_account_id', DB::table('payroll_account_mappings')->where('company_id', $f['mayaId'])->value('salaries_payable_id'))->sum('credit_cents'))->toBe(631709);

    $this->actingAs($f['payroll']);
    $this->post(route('payroll.novelties.store'), ['period_id' => $period, 'payroll_employee_id' => $employee, 'concept_code' => 'BONO', 'amount' => '100'])->assertSessionHasErrors('status');
    $receipt = (int) DB::table('payroll_receipts')->where('payroll_period_id', $period)->value('id');
    expect($this->get(route('payroll.receipt', $receipt))->assertOk()->headers->get('content-type'))->toContain('pdf');

    $this->post(route('payroll.periods.action', [$period, 'send']))->assertSessionHasNoErrors();
    $payment = DB::table('payment_instructions')->where('payroll_period_id', $period)->first();
    expect($payment->kind)->toBe('payroll')->and((int) $payment->amount_cents)->toBe(631709)
        ->and(DB::table('payroll_payment_orders')->where('payroll_period_id', $period)->value('status'))->toBe('sent');

    $this->actingAs($f['accountant'])->post(route('treasury.payments.action', [$payment->id, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['treasury'])->post(route('treasury.payments.action', [$payment->id, 'execute']), ['executed_on' => '2026-09-27', 'reference' => 'NOM-SEP', 'proof' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'), 'bank_account_id' => $f['bankId']])->assertSessionHasNoErrors();
    expect(DB::table('payroll_payment_orders')->where('payroll_period_id', $period)->value('status'))->toBe('paid')
        ->and(DB::table('payroll_periods')->where('id', $period)->value('status'))->toBe('paid');
    $this->assertDatabaseHas('accounting_source_entries', ['source_type' => 'payroll_payment', 'source_id' => $payment->id]);

    // Entries are drafts until Accounting posts them: the reconciliation says so instead of hiding the difference.
    $reconciliation = app(PayrollLedgerReconciliationService::class)->compute($f['mayaId'], '2026-09-30');
    expect($reconciliation['status'])->toBe('difference')->and($reconciliation['items'][0]['reason'])->toBe('Asiento pendiente de contabilizar');

    $this->actingAs($f['payroll'])->post(route('payroll.periods.action', [$period, 'close']))->assertSessionHasNoErrors();
    expect(DB::table('payroll_periods')->where('id', $period)->value('status'))->toBe('closed');

    // The accounting close follows the office payroll policy while the payroll entries are not posted.
    $accountingPeriod = (int) DB::table('accounting_periods')->where('company_id', $f['mayaId'])->where('starts_on', '2026-09-01')->value('id');
    $payrollCheck = fn (): array => collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['mayaId'], $accountingPeriod))->firstWhere('key', 'payroll');
    expect($payrollCheck()['status'])->toBe('warning');
    DB::table('offices')->where('id', $f['office']->id)->update(['payroll_close_policy' => 'block']);
    expect($payrollCheck()['status'])->toBe('blocked');
});

test('data errors block the payroll, a failed payment returns its orders and salaries stay hidden without permission', function () {
    Storage::fake('local');
    $f = payrollFixture();
    $employee = payrollEmployee($f, 500000, ['bank_account_number' => null]);
    $this->actingAs($f['payroll']);
    $this->post(route('payroll.periods.store'), ['company_id' => $f['mayaId'], 'month' => '2026-09'])->assertSessionHasNoErrors();
    $period = (int) DB::table('payroll_periods')->where('company_id', $f['mayaId'])->value('id');

    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();
    expect(DB::table('payroll_results')->where('payroll_period_id', $period)->value('status'))->toBe('error');
    $this->post(route('payroll.periods.action', [$period, 'submit']))->assertSessionHasErrors('status');
    $this->get(route('payroll.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('panel.companies.0.status', 'with_errors')->where('panel.alerts.0.kind', 'calculation'));
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'payroll_errors:'.$period, 'status' => 'open']);

    DB::table('payroll_employees')->where('id', $employee)->update(['bank_account_number' => '3010012345']);
    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();
    $this->post(route('payroll.periods.action', [$period, 'submit']))->assertSessionHasNoErrors();
    $this->actingAs($f['accountant'])->post(route('payroll.periods.action', [$period, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['payroll'])->post(route('payroll.periods.action', [$period, 'send']))->assertSessionHasNoErrors();
    $payment = (int) DB::table('payment_instructions')->where('payroll_period_id', $period)->value('id');
    $this->actingAs($f['accountant'])->post(route('treasury.payments.action', [$payment, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['treasury'])->post(route('treasury.payments.action', [$payment, 'fail']), ['reason' => 'Cuenta del empleado cerrada'])->assertSessionHasNoErrors();
    expect(DB::table('payroll_payment_orders')->where('payroll_period_id', $period)->value('status'))->toBe('failed');
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'payroll_payment_failed:'.$payment, 'type' => 'payroll']);
    $this->actingAs($f['payroll'])->post(route('payroll.periods.action', [$period, 'send']))->assertSessionHasNoErrors();
    expect(DB::table('payment_instructions')->where('payroll_period_id', $period)->count())->toBe(2);

    $roleId = DB::table('workspace_roles')->insertGetId(['organization_id' => $f['org']->id, 'code' => 'payroll_reader', 'name' => 'Consulta de nómina', 'description' => 'Solo lectura', 'is_system' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspace_role_permissions')->insert(['workspace_role_id' => $roleId, 'workspace_permission_id' => DB::table('workspace_permissions')->where('code', 'payroll.dashboard.view')->value('id')]);
    $reader = payrollMember($f, 'payroll_reader');
    DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'user_id' => $reader->id, 'assignment_role' => 'payroll', 'is_primary' => false, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($reader)->get(route('payroll.employees'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('employees.0.base_salary_cents', null)->where('employees.0.bank_account', '****2345'));
    $this->get(route('payroll.receipts'))->assertOk();
});

/** @return array<string, mixed> */
test('the approved payroll gives the month IGSS, IRTRA and INTECAP, due on the 20th of the next month', function () {
    $f = payrollFixture();
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
    $this->actingAs($f['payroll']);
    $this->post(route('payroll.employees.store'), payrollEmployeeForm($f))->assertSessionHasNoErrors();
    $this->post(route('payroll.periods.store'), ['company_id' => $f['mayaId'], 'month' => '2026-09'])->assertSessionHasNoErrors();
    $period = (int) DB::table('payroll_periods')->where('company_id', $f['mayaId'])->value('id');
    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();
    expect(app(OtherTaxesService::class)->employerContributions($f['mayaId'], 2026))->toBe([]);

    DB::table('payroll_periods')->where('id', $period)->update(['status' => 'approved']);
    $line = fn (string $code): int => (int) DB::table('payroll_result_lines')->where('concept_code', $code)->sum('amount_cents');
    $month = app(OtherTaxesService::class)->employerContributions($f['mayaId'], 2026)[0];
    expect($month)->toMatchArray(['month' => '2026-09', 'employees' => 1, 'igss_employee_cents' => $line('IGSS_LABORAL'), 'igss_employer_cents' => $line('IGSS_PATRONAL'),
        'irtra_cents' => $line('IRTRA'), 'intecap_cents' => $line('INTECAP'), 'due_on' => '2026-10-20'])
        ->and($month['total_cents'])->toBe($line('IGSS_LABORAL') + $line('IGSS_PATRONAL') + $line('IRTRA') + $line('INTECAP'))->and($line('INTECAP'))->toBeGreaterThan(0);

    $calendar = collect(app(FiscalObligationService::class)->obligations([$f['mayaId']], now()->startOfMonth(), now()->endOfMonth()));
    expect($calendar->firstWhere('type', 'igss'))->toMatchArray(['name' => 'Planilla IGSS, IRTRA e INTECAP', 'due_on' => '2026-10-20']);
});

test('the libro de salarios gives each worker a folio with the year of approved payrolls, and the IGSS planilla of the month', function () {
    $f = payrollFixture();
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
    $this->actingAs($f['payroll']);
    $this->post(route('payroll.employees.store'), payrollEmployeeForm($f, ['sex' => 'F', 'birth_date' => '1990-05-14', 'nationality' => 'Guatemalteca', 'position' => 'Auxiliar contable']))->assertSessionHasNoErrors();
    $employee = DB::table('payroll_employees')->where('company_id', $f['mayaId'])->first();
    expect($employee->sex)->toBe('F')->and($employee->birth_date)->toBe('1990-05-14');
    $this->post(route('payroll.periods.store'), ['company_id' => $f['mayaId'], 'month' => '2026-09'])->assertSessionHasNoErrors();
    $period = (int) DB::table('payroll_periods')->where('company_id', $f['mayaId'])->value('id');
    $this->post(route('payroll.periods.action', [$period, 'calculate']))->assertSessionHasNoErrors();
    DB::table('payroll_periods')->where('id', $period)->update(['status' => 'approved']);

    $worker = app(SalaryBookService::class)->book($f['mayaId'], 2026)[0];
    $result = DB::table('payroll_results')->where('payroll_period_id', $period)->first();
    expect($worker)->toMatchArray(['name' => 'Ana López', 'sex' => 'Mujer', 'age' => '36 años', 'position' => 'Auxiliar contable', 'hire_date' => '01/01/2026', 'termination_date' => ''])
        ->and($worker['rows'][0])->toMatchArray(['period' => 'septiembre 2026', 'ordinary_cents' => 600000, 'incentive_cents' => 25000, 'net_cents' => (int) $result->net_cents])
        ->and($worker['rows'][0]['deductions_cents'])->toBe($worker['rows'][0]['igss_cents'] + $worker['rows'][0]['isr_cents'] + $worker['rows'][0]['other_deductions_cents'])
        ->and($worker['totals']['net_cents'])->toBe((int) $result->net_cents);

    $planilla = app(SalaryBookService::class)->igssPlanilla($f['mayaId'], 2026, 9);
    expect($planilla)->toHaveCount(1)->and($planilla[0]['igss_number'])->toBe('201234567')->and($planilla[0]['base_cents'])->toBe(600000);

    $this->get(route('payroll.reports.export', ['salary_book', 'format' => 'xlsx']))->assertStatus(422);
    $this->get(route('payroll.reports.export', ['salary_book', 'format' => 'pdf', 'period_id' => $period, 'first_folio' => 15]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('payroll.reports.export', ['salary_book', 'format' => 'xlsx', 'period_id' => $period]))->assertOk();
    $this->get(route('payroll.reports.export', ['igss_planilla', 'format' => 'xlsx', 'period_id' => $period]))->assertOk();
});

function payrollFixture(): array
{
    $org = Organization::create(['name' => 'Atlantia Nómina', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true, 'payment_approval_tiers' => json_encode([['up_to_cents' => null, 'approvals' => 1]])]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['payroll'] = payrollMember($f, 'payroll');
    $f['treasury'] = payrollMember($f, 'treasury');
    $f['accountant'] = payrollMember($f, 'accountant_in_charge');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['payroll'], 'payroll'], [$f['treasury'], 'treasury'], [$f['accountant'], 'accountant_in_charge']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    $ledger = DB::table('ledger_accounts')->insertGetId(['organization_id' => $org->id, 'company_id' => $f['mayaId'], 'code' => '1150', 'name' => 'Banco Industrial', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $f['bankId'] = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $org->id, 'company_id' => $f['mayaId'], 'ledger_account_id' => $ledger, 'bank_name' => 'Banco Industrial', 'account_name' => 'Planillas', 'account_type' => 'checking', 'last_four' => '4521', 'currency' => 'GTQ', 'bank_balance_cents' => 0, 'status' => 'active', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

/** @param array<string, mixed> $f */
function payrollMember(array $f, string $roleCode): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $f
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payrollEmployeeForm(array $f, array $overrides = []): array
{
    return [...['company_id' => $f['mayaId'], 'code' => 'E-001', 'first_name' => 'Ana', 'last_name' => 'López', 'dpi' => '1234567890101', 'igss_number' => '201234567', 'hire_date' => '2026-01-01',
        'pay_frequency' => 'monthly', 'base_salary' => '6000.00', 'incentive_bonus' => '250.00', 'igss_affiliated' => true, 'payment_method' => 'transfer', 'bank_name' => 'Banco Industrial',
        'bank_account_number' => '3010012345', 'bank_account_type' => 'monetaria'], ...$overrides];
}

/**
 * @param  array<string, mixed>  $f
 * @param  array<string, mixed>  $overrides
 */
function payrollEmployee(array $f, int $salaryCents, array $overrides = []): int
{
    return DB::table('payroll_employees')->insertGetId([...[
        'public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'code' => 'E-'.Str::random(4), 'first_name' => 'Luis', 'last_name' => 'Pérez',
        'igss_number' => '301234567', 'hire_date' => '2025-01-01', 'status' => 'active', 'pay_frequency' => 'monthly', 'base_salary_cents' => $salaryCents, 'incentive_bonus_cents' => 25000, 'igss_affiliated' => true,
        'payment_method' => 'transfer', 'bank_name' => 'Banrural', 'bank_account_number' => '3010012345', 'created_at' => now(), 'updated_at' => now(),
    ], ...$overrides]);
}
