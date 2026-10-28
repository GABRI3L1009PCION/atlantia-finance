<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting policies of a company with the history of every change. They are applied by the system: the default
 * depreciation method and lives feed the fixed assets, the capitalization minimum blocks small purchases from being
 * capitalized, and the cost center requirement is enforced on manual journal entries.
 *
 * @phpstan-type PolicyDefinition array{label: string, group: string, type: string, default: string, options?: array<string, string>, help?: string}
 */
class AccountingPolicyService
{
    /** @var array<string, PolicyDefinition> */
    public const CATALOG = [
        'depreciation_method' => ['label' => 'Método de depreciación por defecto', 'group' => 'Activos fijos', 'type' => 'select', 'default' => 'straight_line',
            'options' => ['straight_line' => 'Línea recta', 'declining_balance' => 'Saldo decreciente'], 'help' => 'Se propone al registrar un activo nuevo.'],
        'depreciation_start' => ['label' => 'Inicio de la depreciación', 'group' => 'Activos fijos', 'type' => 'select', 'default' => 'in_service_month',
            'options' => ['in_service_month' => 'Mes en que entra en servicio', 'month_after' => 'Mes siguiente a su entrada en servicio']],
        'capitalization_minimum' => ['label' => 'Monto mínimo para capitalizar un activo (Q)', 'group' => 'Activos fijos', 'type' => 'money', 'default' => '0',
            'help' => 'Compras menores se llevan a gasto y no pueden registrarse como activo fijo. Cero desactiva el control.'],
        'life_buildings' => ['label' => 'Vida útil de edificaciones (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '240'],
        'life_furniture' => ['label' => 'Vida útil de mobiliario y equipo (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '60'],
        'life_machinery' => ['label' => 'Vida útil de maquinaria (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '60'],
        'life_vehicles' => ['label' => 'Vida útil de vehículos (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '60'],
        'life_computers' => ['label' => 'Vida útil de equipo de cómputo (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '36'],
        'life_tools' => ['label' => 'Vida útil de herramientas (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '48'],
        'life_intangible' => ['label' => 'Vida de amortización de intangibles (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '120'],
        'life_other' => ['label' => 'Vida útil de otros bienes (meses)', 'group' => 'Vidas útiles de referencia', 'type' => 'int', 'default' => '120'],
        'ecl_rate_current' => ['label' => 'Pérdida esperada de saldos al día (%)', 'group' => 'NIIF 9 · Pérdida crediticia esperada', 'type' => 'percent', 'default' => '100',
            'help' => 'Porcentaje del saldo por cobrar que se estima no cobrar, según la experiencia de la empresa.'],
        'ecl_rate_1_30' => ['label' => 'Pérdida esperada de 1 a 30 días de atraso (%)', 'group' => 'NIIF 9 · Pérdida crediticia esperada', 'type' => 'percent', 'default' => '300'],
        'ecl_rate_31_60' => ['label' => 'Pérdida esperada de 31 a 60 días de atraso (%)', 'group' => 'NIIF 9 · Pérdida crediticia esperada', 'type' => 'percent', 'default' => '1000'],
        'ecl_rate_61_90' => ['label' => 'Pérdida esperada de 61 a 90 días de atraso (%)', 'group' => 'NIIF 9 · Pérdida crediticia esperada', 'type' => 'percent', 'default' => '2500'],
        'ecl_rate_over_90' => ['label' => 'Pérdida esperada de más de 90 días de atraso (%)', 'group' => 'NIIF 9 · Pérdida crediticia esperada', 'type' => 'percent', 'default' => '5000'],
        'deferred_tax_rate' => ['label' => 'Tasa para el impuesto diferido (%)', 'group' => 'NIC 12 · Impuesto diferido', 'type' => 'percent', 'default' => '2500',
            'help' => 'Tasa del ISR sobre utilidades con la que se miden las diferencias temporarias.'],
        'require_cost_center_on_expenses' => ['label' => 'Exigir centro de costo en los gastos de asientos manuales', 'group' => 'Asientos', 'type' => 'bool', 'default' => '0',
            'help' => 'Aplica a asientos manuales y ajustes; los asientos automáticos no se ven afectados.'],
    ];

    /** @var array<int, array<string, string>> */
    private array $loaded = [];

    public function __construct(private AccountingService $accounting) {}

    public function value(int $companyId, string $key): string
    {
        $this->loaded[$companyId] ??= DB::table('accounting_policies')->where('company_id', $companyId)->pluck('value', 'key')->all();

        return (string) ($this->loaded[$companyId][$key] ?? self::CATALOG[$key]['default'] ?? '');
    }

    public function int(int $companyId, string $key): int
    {
        return (int) $this->value($companyId, $key);
    }

    public function bool(int $companyId, string $key): bool
    {
        return $this->value($companyId, $key) === '1';
    }

    /** A percentage policy in basis points (2.5 % is 250). */
    public function basisPoints(int $companyId, string $key): int
    {
        return (int) $this->value($companyId, $key);
    }

    /** Capitalization minimum in cents (0 when the control is off). */
    public function capitalizationMinimumCents(int $companyId): int
    {
        return (int) $this->value($companyId, 'capitalization_minimum');
    }

    /** @return list<array<string, mixed>> */
    public function all(int $companyId): array
    {
        $rows = DB::table('accounting_policies')->where('company_id', $companyId)->get()->keyBy('key');
        $result = [];
        foreach (self::CATALOG as $key => $definition) {
            $row = $rows[$key] ?? null;
            $value = (string) ($row->value ?? $definition['default']);
            $result[] = ['key' => $key, 'label' => $definition['label'], 'group' => $definition['group'], 'type' => $definition['type'], 'options' => $definition['options'] ?? null,
                'help' => $definition['help'] ?? null, 'value' => in_array($definition['type'], ['money', 'percent'], true) ? number_format((int) $value / 100, 2, '.', '') : $value, 'default' => $definition['default'], 'customized' => $row !== null];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $companyId, int $limit = 40): array
    {
        return array_values(DB::table('accounting_policy_versions as v')->leftJoin('users', 'users.id', '=', 'v.changed_by')->where('v.company_id', $companyId)->orderByDesc('v.id')->limit($limit)
            ->get(['v.key', 'v.old_value', 'v.new_value', 'v.reason', 'v.created_at', 'users.name as changed_by'])
            ->map(fn ($row): array => ['key' => (string) $row->key, 'label' => self::CATALOG[$row->key]['label'] ?? (string) $row->key, 'old' => $row->old_value === null ? null : $this->display((string) $row->key, (string) $row->old_value),
                'new' => $this->display((string) $row->key, (string) $row->new_value), 'reason' => $row->reason, 'by' => $row->changed_by === null ? null : (string) $row->changed_by, 'at' => (string) $row->created_at])->all());
    }

    public function set(Organization $organization, User $user, int $companyId, string $key, string $input, ?string $reason): void
    {
        $definition = self::CATALOG[$key] ?? throw ValidationException::withMessages(['key' => 'La política no existe.']);
        $value = $this->normalize($definition, trim($input));
        $current = DB::table('accounting_policies')->where('company_id', $companyId)->where('key', $key)->first();
        if (($current->value ?? $definition['default']) === $value) {
            return;
        }
        DB::transaction(function () use ($organization, $user, $companyId, $key, $value, $reason, $current): void {
            $this->accounting->lock($companyId);
            DB::table('accounting_policies')->updateOrInsert(['company_id' => $companyId, 'key' => $key], ['organization_id' => $organization->id, 'value' => $value, 'updated_by' => $user->id,
                'updated_at' => now(), ...($current === null ? ['created_at' => now()] : [])]);
            $policyId = (int) DB::table('accounting_policies')->where('company_id', $companyId)->where('key', $key)->value('id');
            DB::table('accounting_policy_versions')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'accounting_policy_id' => $policyId, 'key' => $key,
                'old_value' => $current->value ?? null, 'new_value' => $value, 'reason' => $reason === null ? null : mb_substr($reason, 0, 255), 'changed_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'accounting_policy.changed', 'companies', $companyId, ['key' => $key, 'from' => $current->value ?? null, 'to' => $value]);
        });
        unset($this->loaded[$companyId]);
    }

    /** @param PolicyDefinition $definition */
    private function normalize(array $definition, string $input): string
    {
        return match ($definition['type']) {
            'select' => array_key_exists($input, $definition['options'] ?? []) ? $input : throw ValidationException::withMessages(['value' => 'Selecciona una opción válida.']),
            'bool' => in_array($input, ['1', 'true', 'on'], true) ? '1' : '0',
            'int' => ctype_digit($input) && (int) $input >= 1 && (int) $input <= 600 ? (string) (int) $input : throw ValidationException::withMessages(['value' => 'Indica un número de meses entre 1 y 600.']),
            'money' => (string) $this->accounting->cents($input === '' ? '0' : $input),
            // Percentages are kept in hundredths of a percent (basis points).
            'percent' => ($bp = $this->accounting->cents($input === '' ? '0' : $input)) <= 10000 ? (string) $bp : throw ValidationException::withMessages(['value' => 'Indica un porcentaje entre 0 y 100.']),
            default => $input,
        };
    }

    private function display(string $key, string $value): string
    {
        $definition = self::CATALOG[$key] ?? null;

        return match ($definition['type'] ?? '') {
            'select' => $definition['options'][$value] ?? $value,
            'bool' => $value === '1' ? 'Sí' : 'No',
            'money' => 'Q '.number_format((int) $value / 100, 2),
            'percent' => number_format((int) $value / 100, 2).' %',
            default => $value,
        };
    }
}
