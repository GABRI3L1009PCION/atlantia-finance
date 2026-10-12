<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Services\AccountingAutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Per-company payroll setup: the system concepts (with their IGSS/ISR treatment) and the ledger accounts of the
 * payroll entries. Missing accounts are created once in the company's chart; existing ones are reused.
 */
class PayrollSetupService
{
    /** code => [name, type, affects IGSS, affects ISR, sort] */
    public const SYSTEM_CONCEPTS = [
        'SALARIO' => ['Salario ordinario', 'earning', true, true, 10],
        'BONIF_INCENTIVO' => ['Bonificación incentivo', 'earning', false, true, 20],
        'HORAS_EXTRA' => ['Horas extra', 'earning', true, true, 30],
        'COMISION' => ['Comisiones', 'earning', true, true, 40],
        'BONO' => ['Bonos', 'earning', true, true, 50],
        'OTRO_INGRESO' => ['Otros ingresos', 'earning', true, true, 60],
        'IGSS_LABORAL' => ['Cuota laboral IGSS', 'deduction', false, false, 110],
        'ISR' => ['Retención ISR', 'deduction', false, false, 120],
        'ANTICIPO' => ['Anticipo de salario', 'deduction', false, false, 130],
        'PRESTAMO' => ['Cuota de préstamo', 'deduction', false, false, 140],
        'DESCUENTO' => ['Otros descuentos', 'deduction', false, false, 150],
        'IGSS_PATRONAL' => ['Cuota patronal IGSS', 'employer', false, false, 210],
        'IRTRA' => ['Cuota IRTRA', 'employer', false, false, 220],
        'INTECAP' => ['Cuota INTECAP', 'employer', false, false, 230],
        'PROV_AGUINALDO' => ['Provisión aguinaldo', 'provision', false, false, 310],
        'PROV_BONO14' => ['Provisión Bono 14', 'provision', false, false, 320],
        'PROV_INDEMNIZACION' => ['Provisión indemnización', 'provision', false, false, 330],
        'PROV_VACACIONES' => ['Provisión vacaciones', 'provision', false, false, 340],
    ];

    /** Concepts a person may register as a novelty; AUSENCIA reduces the days worked instead of being a line. */
    public const NOVELTY_CONCEPTS = [
        'HORAS_EXTRA' => ['Horas extra', 'quantity', 'horas'],
        'COMISION' => ['Comisión', 'amount', null],
        'BONO' => ['Bono', 'amount', null],
        'OTRO_INGRESO' => ['Otro ingreso', 'amount', null],
        'AUSENCIA' => ['Ausencia no remunerada', 'quantity', 'días'],
        'DESCUENTO' => ['Descuento', 'amount', null],
    ];

    /** key => [code, name, type] of the accounts the payroll entries use. */
    private const ACCOUNTS = [
        'salaries_expense_id' => ['5202', 'Sueldos y salarios', 'expense'],
        'employer_expense_id' => ['5208', 'Cuotas patronales IGSS, IRTRA e INTECAP', 'expense'],
        'benefits_expense_id' => ['5209', 'Prestaciones laborales', 'expense'],
        'salaries_payable_id' => ['2105', 'Sueldos por pagar', 'liability'],
        'social_security_payable_id' => ['2106', 'Cuotas IGSS, IRTRA e INTECAP por pagar', 'liability'],
        'isr_payable_id' => ['2107', 'ISR retenido a empleados por pagar', 'liability'],
        'provisions_id' => ['2108', 'Provisiones laborales', 'liability'],
        'other_deductions_payable_id' => ['2109', 'Descuentos a empleados por pagar', 'liability'],
        'employee_receivables_id' => ['1106', 'Anticipos y préstamos a empleados', 'asset'],
    ];

    public function __construct(private AccountingAutomationService $automation) {}

    public function ensure(int $companyId): stdClass
    {
        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'organization_id']);
        if ($company === null) {
            throw ValidationException::withMessages(['company' => 'Empresa no encontrada.']);
        }
        $this->automation->ensureMapping($companyId);
        $existing = DB::table('payroll_concepts')->where('company_id', $companyId)->pluck('code')->all();
        foreach (self::SYSTEM_CONCEPTS as $code => [$name, $type, $igss, $isr, $sort]) {
            if (! in_array($code, $existing, true)) {
                DB::table('payroll_concepts')->insert(['organization_id' => $company->organization_id, 'company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type,
                    'affects_igss' => $igss, 'affects_isr' => $isr, 'is_system' => true, 'active' => true, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $mapping = DB::table('payroll_account_mappings')->where('company_id', $companyId)->first();
        if ($mapping !== null) {
            return $mapping;
        }
        $ids = [];
        foreach (self::ACCOUNTS as $key => [$code, $name, $type]) {
            $id = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->value('id');
            $ids[$key] = $id ?? DB::table('ledger_accounts')->insertGetId(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('payroll_account_mappings')->insert([...$ids, 'organization_id' => $company->organization_id, 'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('payroll_account_mappings')->where('company_id', $companyId)->first();
    }

    /**
     * Active concepts of the company keyed by code.
     *
     * @return array<string, stdClass>
     */
    public function concepts(int $companyId): array
    {
        return DB::table('payroll_concepts')->where('company_id', $companyId)->where('active', true)->orderBy('sort')->get()->keyBy('code')->all();
    }
}
