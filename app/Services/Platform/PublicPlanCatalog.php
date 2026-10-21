<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Plans shown on the public sales page: only active plans with a published version and active GTQ prices. Prices
 * come from the plan prices (what checkout charges), capacity lines from the published version limits and the
 * commercial copy from the plan presentation edited in the Control Center. The annual bonus is derived from the
 * prices, never written by hand.
 */
class PublicPlanCatalog
{
    public function __construct(private TenantDatabaseContext $tenancy) {}

    /**
     * @return list<array{code: string, name: string, tagline: string|null, description: string, monthly_price: float, annual_price: float, currency: string,
     *     months_included: int, annual_savings: float, recommended: bool, price_prefix: string|null, cta_label: string|null,
     *     features: list<array{text: string, hint: string|null}>}>
     */
    public function plans(): array
    {
        return $this->tenancy->runAsSystem(function (): array {
            $plans = [];
            $activePlans = DB::table('plans')->where('is_active', true)->orderBy('display_order')->orderBy('id')->get();
            $payrollFrom = $this->firstPlanWithPayroll($activePlans);
            foreach ($activePlans as $plan) {
                $version = DB::table('plan_versions')->where('plan_id', $plan->id)->where('status', 'published')->orderByDesc('version')->first();
                $prices = DB::table('plan_prices')->where('plan_id', $plan->id)->where('is_active', true)->where('currency_code', 'GTQ')->pluck('amount', 'billing_period');
                if ($version === null || ! isset($prices['monthly'], $prices['annual'])) {
                    continue;
                }

                $monthly = (float) $prices['monthly'];
                $annual = (float) $prices['annual'];
                $plans[] = [
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'tagline' => $plan->tagline,
                    'description' => (string) ($version->description ?: $plan->description),
                    'monthly_price' => $monthly,
                    'annual_price' => $annual,
                    'currency' => 'GTQ',
                    'months_included' => $monthly > 0 ? max(0, (int) round(12 - $annual / $monthly)) : 0,
                    'annual_savings' => max(0, round($monthly * 12 - $annual, 2)),
                    'recommended' => (bool) $plan->is_recommended,
                    'price_prefix' => $plan->price_prefix,
                    'cta_label' => $plan->cta_label,
                    'features' => [...$this->capacity($version), ...$this->payrollNote($version, $payrollFrom), ...array_map(
                        fn (string $text): array => ['text' => $text, 'hint' => null],
                        array_values(array_filter((array) json_decode((string) ($plan->highlights ?? '[]'), true), 'is_string')),
                    )],
                ];
            }

            return $plans;
        });
    }

    /**
     * Name of the first plan, in display order, whose published version includes payroll.
     *
     * @param  Collection<int, stdClass>  $activePlans
     */
    private function firstPlanWithPayroll(Collection $activePlans): ?string
    {
        foreach ($activePlans as $plan) {
            $version = DB::table('plan_versions')->where('plan_id', $plan->id)->where('status', 'published')->orderByDesc('version')->first();
            if ($version !== null && in_array('payroll', (array) json_decode((string) $version->entitlements, true), true)) {
                return (string) $plan->name;
            }
        }

        return null;
    }

    /**
     * A plan without payroll says so, with the plan that has it, so nobody buys it expecting payroll.
     *
     * @return list<array{text: string, hint: string|null}>
     */
    private function payrollNote(stdClass $version, ?string $payrollFrom): array
    {
        if (in_array('payroll', (array) json_decode((string) $version->entitlements, true), true)) {
            return [];
        }

        return [['text' => $payrollFrom === null ? 'Nómina no incluida' : 'Nómina no incluida (disponible desde el plan '.$payrollFrom.')', 'hint' => null]];
    }

    /**
     * Capacity lines written from the published version limits (null means "a medida").
     *
     * @return list<array{text: string, hint: string|null}>
     */
    private function capacity(stdClass $version): array
    {
        $limits = (array) json_decode((string) $version->limits, true);
        $value = fn (string $metric): ?int => array_key_exists($metric, $limits) && ($limits[$metric]['value'] ?? null) !== null ? (int) $limits[$metric]['value'] : null;
        $number = fn (int $amount): string => number_format($amount, 0, '.', ',');
        $lines = [];

        if (array_key_exists('offices', $limits)) {
            $offices = $value('offices');
            $lines[] = $offices === null ? 'Oficinas a medida' : ($offices === 1 ? '1 oficina' : 'Hasta '.$number($offices).' oficinas');
        }
        if (array_key_exists('users', $limits)) {
            $users = $value('users');
            $cases = $value('active_cases');
            $lines[] = match (true) {
                $users === null => 'Usuarios y empresas cliente a medida',
                $cases === null => $number($users).' '.($users === 1 ? 'usuario' : 'usuarios'),
                default => $number($users).' '.($users === 1 ? 'usuario' : 'usuarios').' y '.$number($cases).' empresas cliente',
            };
        }
        if (array_key_exists('storage_bytes', $limits)) {
            $bytes = $value('storage_bytes');
            $lines[] = $bytes === null ? 'Almacenamiento a medida' : $number((int) round($bytes / 1073741824)).' GB de almacenamiento';
        }
        $result = array_map(fn (string $text): array => ['text' => $text, 'hint' => null], $lines);
        if (array_key_exists('ai_queries', $limits)) {
            $queries = $value('ai_queries');
            $result[] = ['text' => $queries === null ? 'Consultas de Luzma personalizadas' : $number($queries).' consultas de Luzma al mes',
                'hint' => 'Luzma es el asistente con IA de Atlantia Finance: cada pregunta que le haces cuenta como una consulta.'];
        }

        return $result;
    }
}
