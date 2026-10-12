<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing installations: the current catalog becomes version 1 of each plan and every subscription is linked to the
 * version of its plan, so later plan edits never change what was already sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('atlantia.system', 'on', false)");
        foreach (DB::table('plans')->get() as $plan) {
            if (DB::table('plan_versions')->where('plan_id', $plan->id)->exists()) {
                continue;
            }
            $limits = [];
            foreach (DB::table('plan_limits')->where('plan_id', $plan->id)->get() as $limit) {
                DB::table('plan_limits')->where('id', $limit->id)->update(['enforcement' => 'hard']);
                $limits[$limit->metric] = ['value' => $limit->limit_value === null ? null : (int) $limit->limit_value, 'unit' => $limit->unit, 'warning' => 80, 'high' => 95, 'enforcement' => 'hard'];
            }
            $prices = DB::table('plan_prices')->where('plan_id', $plan->id)->where('currency_code', 'GTQ')->pluck('amount', 'billing_period');
            $versionId = DB::table('plan_versions')->insertGetId([
                'plan_id' => $plan->id, 'version' => 1, 'name' => $plan->name, 'description' => $plan->description, 'currency_code' => 'GTQ',
                'monthly_price' => $prices['monthly'] ?? 0, 'annual_price' => $prices['annual'] ?? 0,
                'entitlements' => json_encode(DB::table('feature_plan')->join('features', 'features.id', '=', 'feature_plan.feature_id')->where('plan_id', $plan->id)->pluck('features.code')->values()->all()),
                'limits' => json_encode($limits), 'status' => 'published', 'change_note' => 'Versión inicial del catálogo.', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('subscriptions')->whereNull('plan_version_id')
                ->whereIn('id', DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->where('plan_prices.plan_id', $plan->id)->select('subscription_items.subscription_id'))
                ->update(['plan_version_id' => $versionId]);
        }
        foreach (DB::table('organizations')->whereNull('code')->get(['id', 'name']) as $organization) {
            DB::table('organizations')->where('id', $organization->id)->update(['code' => 'ORG-'.str_pad((string) $organization->id, 5, '0', STR_PAD_LEFT)]);
        }
        DB::statement("SELECT set_config('atlantia.system', 'off', false)");
    }

    public function down(): void
    {
        DB::table('subscriptions')->update(['plan_version_id' => null]);
        DB::table('plan_versions')->delete();
    }
};
