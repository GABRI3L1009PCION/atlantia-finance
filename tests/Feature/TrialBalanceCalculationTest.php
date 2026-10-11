<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('trial balance calculates opening period and closing balances from posted entries only', function () {
    $f = trialBalanceFixture();
    trialBalanceEntry($f, $f['august'], '2026-08-31', [
        [$f['bank'], 25000000, 0],
        [$f['capital'], 0, 25000000],
    ], 'posted', 'opening');
    trialBalanceEntry($f, $f['september'], '2026-09-15', [
        [$f['bank'], 2500000, 0],
        [$f['income'], 0, 2500000],
    ]);
    trialBalanceEntry($f, $f['september'], '2026-09-16', [
        [$f['expense'], 500000, 0],
        [$f['bank'], 0, 500000],
    ]);
    trialBalanceEntry($f, $f['september'], '2026-09-20', [
        [$f['bank'], 5000000, 0],
        [$f['income'], 0, 5000000],
    ], 'draft');

    $trial = app(TrialBalanceService::class)->build($f['companyId'], '2026-09-01', '2026-09-30', ['accounts' => 'all']);
    $bank = collect($trial['rows'])->firstWhere('account_id', $f['bank']);

    expect($bank['opening_debit_cents'])->toBe(25000000)
        ->and($bank['period_debit_cents'])->toBe(2500000)
        ->and($bank['period_credit_cents'])->toBe(500000)
        ->and($bank['closing_debit_cents'])->toBe(27000000)
        ->and($trial['summary']['period_debits_cents'])->toBe(3000000)
        ->and($trial['summary']['period_credits_cents'])->toBe(3000000)
        ->and($trial['summary']['movement_difference_cents'])->toBe(0)
        ->and($trial['integrity']['movement_balanced'])->toBeTrue();
});

test('trial balance marks analytical filtered views without treating them as official corruption', function () {
    $f = trialBalanceFixture();
    trialBalanceEntry($f, $f['september'], '2026-09-10', [
        [$f['bank'], 1000000, 0, 'Ventas'],
        [$f['income'], 0, 1000000, null],
    ]);

    $trial = app(TrialBalanceService::class)->build($f['companyId'], '2026-09-01', '2026-09-30', [
        'accounts' => 'all',
        'cost_center' => 'Ventas',
    ]);

    expect($trial['mode'])->toBe('analytical')
        ->and($trial['summary']['period_debits_cents'])->toBe(1000000)
        ->and($trial['summary']['period_credits_cents'])->toBe(0)
        ->and($trial['integrity']['movement_balanced'])->toBeFalse();
});

function trialBalanceFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::create(['name' => 'Oficina trial', 'status' => 'active']);
    $office = $org->offices()->create(['code' => 'TB', 'name' => 'Trial', 'status' => 'active', 'is_primary' => true]);
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Trial Balance', 'tax_id' => 'TB-1', 'currency' => 'GTQ']);
    $bank = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '1102', 'name' => 'Bancos', 'type' => 'asset']);
    $capital = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '3101', 'name' => 'Capital', 'type' => 'equity']);
    $income = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '4101', 'name' => 'Ingresos', 'type' => 'income']);
    $expense = DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => '5101', 'name' => 'Gastos', 'type' => 'expense']);
    $august = DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => 'Agosto 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);
    $september = DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);

    return compact('user', 'companyId', 'bank', 'capital', 'income', 'expense', 'august', 'september');
}

function trialBalanceEntry(array $f, int $periodId, string $date, array $lines, string $status = 'posted', string $kind = 'standard'): void
{
    $entryId = DB::table('journal_entries')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'accounting_period_id' => $periodId,
        'entry_date' => $date,
        'description' => 'Asiento trial',
        'kind' => $kind,
        'status' => 'draft',
        'created_by' => $f['user']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($lines as $index => $line) {
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entryId,
            'ledger_account_id' => $line[0],
            'position' => $index + 1,
            'description' => null,
            'cost_center' => $line[3] ?? null,
            'debit_cents' => $line[1],
            'credit_cents' => $line[2],
        ]);
    }

    // Entries follow the real workflow: lines are written while draft, then the entry is posted.
    if (in_array($status, ['posted', 'reversed'], true)) {
        DB::table('journal_entries')->where('id', $entryId)->update([
            'status' => 'posted',
            'number' => DB::table('journal_entries')->where('accounting_period_id', $periodId)->whereNotNull('number')->count() + 1,
            'posted_by' => $f['user']->id,
            'posted_at' => now(),
        ]);
    }

    if ($status !== 'draft' && $status !== 'posted') {
        DB::table('journal_entries')->where('id', $entryId)->update(['status' => $status]);
    }
}
