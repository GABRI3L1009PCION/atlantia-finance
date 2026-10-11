<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Legal payroll parameters with validity (IGSS, IRTRA, INTECAP, ISR scale, incentive bonus, minimum wage,
 * provisions). A firm's own value wins over Atlantia's shared one; a missing parameter stops the calculation
 * instead of being guessed.
 */
class PayrollParameterService
{
    /** @var array<string, float|null> */
    private array $cache = [];

    public function value(int $organizationId, string $code, string $date): float
    {
        $value = $this->find($organizationId, $code, $date);
        if ($value === null) {
            throw ValidationException::withMessages(['parameters' => 'Falta el parámetro legal "'.$code.'" vigente al '.$date.'. Regístralo en los parámetros de nómina antes de calcular.']);
        }

        return $value;
    }

    public function find(int $organizationId, string $code, string $date): ?float
    {
        $key = $organizationId.':'.$code.':'.$date;
        if (! array_key_exists($key, $this->cache)) {
            $value = DB::table('payroll_parameters')->where('code', $code)->where('effective_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
                ->orderByRaw('organization_id IS NULL')->orderByDesc('effective_from')->value('value');
            $this->cache[$key] = $value === null ? null : (float) $value;
        }

        return $this->cache[$key];
    }

    /**
     * Parameters in force at a date, for the parameters screen.
     *
     * @return list<array{code: string, label: string, value: float, unit: string, effective_from: string, effective_to: string|null, legal_reference: string, own: bool}>
     */
    public function current(int $organizationId, string $date): array
    {
        $rows = DB::table('payroll_parameters')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->orderByRaw('organization_id IS NULL')->orderByDesc('effective_from')->get()->unique('code');

        return array_values($rows->sortBy('code')->map(fn ($row): array => ['code' => (string) $row->code, 'label' => (string) $row->label, 'value' => (float) $row->value, 'unit' => (string) $row->unit,
            'effective_from' => (string) $row->effective_from, 'effective_to' => $row->effective_to, 'legal_reference' => (string) $row->legal_reference, 'own' => $row->organization_id !== null])->all());
    }

    /**
     * Loads Atlantia's shared values from database/data/payroll/parameters.json (idempotent).
     */
    public function syncShared(?string $file = null): int
    {
        $data = json_decode((string) file_get_contents($file ?? database_path('data/payroll/parameters.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['parameters'] as $parameter) {
            $exists = DB::table('payroll_parameters')->whereNull('organization_id')->where('code', $parameter['code'])->where('effective_from', $parameter['effective_from'])->value('id');
            $values = ['label' => $parameter['label'], 'value' => $parameter['value'], 'unit' => $parameter['unit'], 'effective_to' => $parameter['effective_to'], 'legal_reference' => $parameter['legal_reference'], 'updated_at' => now()];
            $exists === null
                ? DB::table('payroll_parameters')->insert([...$values, 'organization_id' => null, 'code' => $parameter['code'], 'effective_from' => $parameter['effective_from'], 'created_at' => now()])
                : DB::table('payroll_parameters')->where('id', $exists)->update($values);
        }
        $this->cache = [];

        return count($data['parameters']);
    }
}
