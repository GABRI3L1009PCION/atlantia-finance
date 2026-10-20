<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo monthly charges for the contracts of the demo director's firm: older months collected, the previous month with
 * one overdue charge, the current month partly collected. Safe to run twice: it does nothing when charges already exist.
 */
class RevenueDemoSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction();

        $organizationId = (int) DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('users.email', 'socio.director@atlantia.test')
            ->value('organization_user.organization_id');

        if ($organizationId === 0 || DB::table('crm_invoices')->where('organization_id', $organizationId)->exists()) {
            return;
        }

        $now = CarbonImmutable::now();
        $contracts = DB::table('crm_contracts')->where('organization_id', $organizationId)->whereIn('status', ['active', 'onboarding', 'at_risk'])->orderBy('id')->get();
        $number = 1;

        foreach (range(5, 0) as $back) {
            $month = $now->startOfMonth()->subMonths($back);

            foreach ($contracts as $index => $contract) {
                $due = $month->addDays(14);
                $paid = $back > 1 || ($back === 1 && $index !== 1) || ($back === 0 && $index % 2 === 0);

                DB::table('crm_invoices')->insert([
                    'organization_id' => $organizationId,
                    'company_id' => $contract->company_id,
                    'crm_contract_id' => $contract->id,
                    'number' => 'COB-'.str_pad((string) $number++, 4, '0', STR_PAD_LEFT),
                    'concept' => $contract->services.' – '.ucfirst($month->settings(['locale' => 'es'])->translatedFormat('F Y')),
                    'amount_cents' => $contract->monthly_fee_cents,
                    'issued_on' => $month->toDateString(),
                    'due_on' => $due->toDateString(),
                    'status' => $paid ? 'paid' : 'pending',
                    'paid_on' => $paid ? $month->addDays(8 + $index)->min($now)->toDateString() : null,
                    'created_at' => $month,
                    'updated_at' => $month,
                ]);
            }
        }
    }
}
