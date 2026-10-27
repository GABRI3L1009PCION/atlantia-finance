<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The Guatemalan taxes outside VAT and ISR, as reference calculations prepared from the company's own data:
 *  - IUSI (Decreto 15-98, reformed by Decreto 18-2026): an annual rate per thousand on the registered value of each
 *    property, paid in four quarters. The rate tables are versioned by date, so the reform applies from its effective
 *    day without touching the earlier quarters.
 *  - Timbres fiscales (Decreto 37-92): 3 % on the contracts and other taxed documents, with their draft entry.
 *  - Employer contributions: IGSS (employee and employer shares), IRTRA and INTECAP of the approved payrolls, paid
 *    together within the first twenty days of the following month.
 *  - ISO (Decreto 73-2008): the quarterly tax and how much of it can be credited against the quarterly ISR payments.
 */
class OtherTaxesService
{
    public const PROPERTY_USES = ['residential' => 'Vivienda', 'mixed' => 'Uso mixto', 'commercial' => 'Comercial', 'land' => 'Terreno', 'other' => 'Otro'];

    /**
     * IUSI rate tables by date of entry into force. Each bracket is [upper value in cents or null, rate in basis points
     * of the value: 2 por millar = 20]; the rate of the bracket applies to the whole value.
     *
     * @var list<array{from: string, law: string, exempt_uses: list<string>, brackets: list<array{0: int|null, 1: int}>}>
     */
    public const IUSI_TABLES = [
        ['from' => '1998-01-01', 'law' => 'Decreto 15-98', 'exempt_uses' => [], 'brackets' => [[200000, 0], [2000000, 20], [7000000, 60], [null, 90]]],
        ['from' => '2026-11-26', 'law' => 'Decreto 18-2026', 'exempt_uses' => ['residential', 'mixed'], 'brackets' => [[50000000, 30], [100000000, 60], [null, 90]]],
    ];

    /** General rate of the timbres fiscales on documents (Decreto 37-92, artículo 4). */
    public const STAMP_RATE_BP = 300;

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private IsoService $iso,
        private IsrService $isr,
    ) {}

    /**
     * @return array{law: string, rate_bp: int, annual_cents: int, quarterly_cents: int}
     */
    public function iusiAnnual(int $valueCents, string $use, string $date): array
    {
        $table = collect(self::IUSI_TABLES)->filter(fn (array $table): bool => $table['from'] <= $date)->last() ?? self::IUSI_TABLES[0];
        $rate = 0;
        if (! in_array($use, $table['exempt_uses'], true)) {
            foreach ($table['brackets'] as [$limit, $bracketRate]) {
                $rate = $bracketRate;
                if ($limit === null || $valueCents <= $limit) {
                    break;
                }
            }
        }
        $annual = intdiv($valueCents * $rate + 5000, 10000);

        return ['law' => $table['law'], 'rate_bp' => $rate, 'annual_cents' => $annual, 'quarterly_cents' => intdiv($annual + 2, 4)];
    }

    /**
     * The company's properties with the IUSI of each quarter of the year, by the law in force when the quarter starts.
     *
     * @return array{properties: list<array<string, mixed>>, quarters: list<array{quarter: int, label: string, due_on: string, law: string, tax_cents: int}>}
     */
    public function iusi(int $companyId, int $year): array
    {
        $properties = DB::table('iusi_properties')->where('company_id', $companyId)->orderByDesc('is_active')->orderBy('name')->get();
        $quarters = [];
        foreach (range(1, 4) as $quarter) {
            $start = Carbon::create($year, $quarter * 3 - 2, 1);
            $laws = [];
            $tax = 0;
            foreach ($properties->where('is_active', true) as $property) {
                $result = $this->iusiAnnual((int) $property->value_cents, (string) $property->use, $start->toDateString());
                $tax += $result['quarterly_cents'];
                $laws[$result['law']] = true;
            }
            $quarters[] = ['quarter' => $quarter, 'label' => 'Trimestre '.$quarter, 'due_on' => $start->copy()->addMonths(3)->endOfMonth()->toDateString(),
                'law' => implode(', ', array_keys($laws)) ?: self::IUSI_TABLES[0]['law'], 'tax_cents' => $tax];
        }

        return [
            'properties' => array_values($properties->map(function (stdClass $property) use ($year): array {
                $now = $this->iusiAnnual((int) $property->value_cents, (string) $property->use, Carbon::create($year, 1, 1)->toDateString());

                return ['id' => (int) $property->id, 'name' => (string) $property->name, 'registry' => $property->registry, 'municipality' => (string) $property->municipality,
                    'use' => (string) $property->use, 'use_label' => self::PROPERTY_USES[$property->use] ?? $property->use, 'value_cents' => (int) $property->value_cents,
                    'is_active' => (bool) $property->is_active, 'rate_bp' => $now['rate_bp'], 'annual_cents' => $now['annual_cents'], 'law' => $now['law']];
            })->all()),
            'quarters' => $quarters,
        ];
    }

    /**
     * @param  array{name: string, registry: string|null, municipality: string, use: string, value_cents: int}  $data
     */
    public function saveProperty(Organization $organization, User $user, int $companyId, ?int $propertyId, array $data): int
    {
        if ($propertyId === null) {
            $propertyId = DB::table('iusi_properties')->insertGetId([...$data, 'organization_id' => $organization->id, 'company_id' => $companyId, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            abort_if(DB::table('iusi_properties')->where('company_id', $companyId)->where('id', $propertyId)->update([...$data, 'updated_at' => now()]) === 0, 404);
        }
        $this->accounting->audit($organization, $user, 'fiscal.iusi_property.saved', 'iusi_properties', $propertyId, ['value_cents' => $data['value_cents'], 'use' => $data['use']]);

        return $propertyId;
    }

    public function deactivateProperty(Organization $organization, User $user, int $companyId, int $propertyId): void
    {
        abort_if(DB::table('iusi_properties')->where('company_id', $companyId)->where('id', $propertyId)->update(['is_active' => false, 'updated_at' => now()]) === 0, 404);
        $this->accounting->audit($organization, $user, 'fiscal.iusi_property.deactivated', 'iusi_properties', $propertyId);
    }

    /** Drafts the IUSI of one quarter: expense against IUSI payable. */
    public function draftIusi(Organization $organization, User $user, int $companyId, int $year, int $quarter): int
    {
        $row = collect($this->iusi($companyId, $year)['quarters'])->firstWhere('quarter', $quarter);
        if ($row === null || $row['tax_cents'] <= 0) {
            throw ValidationException::withMessages(['quarter' => 'Ese trimestre no tiene IUSI que registrar.']);
        }
        $date = Carbon::create($year, $quarter * 3, 1)->endOfMonth()->toDateString();

        return $this->draft($organization, $user, $companyId, 'iusi_quarter', $year * 10 + $quarter, $date, 'IUSI trimestre '.$quarter.' de '.$year, '5260', '2218', $row['tax_cents']);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, monthly: list<array{month: string, label: string, taxable_cents: int, tax_cents: int}>, total_cents: int}
     */
    public function stamps(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('stamp_tax_documents')->where('company_id', $companyId)->whereBetween('document_date', [$from, $to])->orderBy('document_date')->orderBy('id')->get();
        $monthly = [];
        foreach ($rows->whereNull('voided_at') as $row) {
            $month = substr((string) $row->document_date, 0, 7);
            $monthly[$month] ??= ['month' => $month, 'label' => self::MONTHS[(int) substr($month, 5, 2) - 1].' '.substr($month, 0, 4), 'taxable_cents' => 0, 'tax_cents' => 0];
            $monthly[$month]['taxable_cents'] += (int) $row->taxable_cents;
            $monthly[$month]['tax_cents'] += (int) $row->tax_cents;
        }
        ksort($monthly);

        return [
            'rows' => array_values($rows->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'date' => (string) $row->document_date, 'description' => (string) $row->description,
                'counterparty' => $row->counterparty, 'taxable_cents' => (int) $row->taxable_cents, 'rate_bp' => (int) $row->rate_bp, 'tax_cents' => (int) $row->tax_cents, 'voided' => $row->voided_at !== null])->all()),
            'monthly' => array_values($monthly),
            'total_cents' => (int) $rows->whereNull('voided_at')->sum('tax_cents'),
        ];
    }

    /**
     * Records a document taxed with timbres fiscales and drafts its entry (expense against timbres payable).
     *
     * @param  array{document_date: string, description: string, counterparty: string|null, taxable_cents: int}  $data
     */
    public function addStamp(Organization $organization, User $user, int $companyId, array $data): int
    {
        $tax = intdiv($data['taxable_cents'] * self::STAMP_RATE_BP + 5000, 10000);
        $id = DB::table('stamp_tax_documents')->insertGetId([...$data, 'organization_id' => $organization->id, 'company_id' => $companyId, 'rate_bp' => self::STAMP_RATE_BP, 'tax_cents' => $tax,
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->draft($organization, $user, $companyId, 'stamp_tax', $id, $data['document_date'], 'Timbres fiscales: '.$data['description'], '5259', '2209', $tax);
        $this->accounting->audit($organization, $user, 'fiscal.stamp_tax.recorded', 'stamp_tax_documents', $id, ['taxable_cents' => $data['taxable_cents'], 'tax_cents' => $tax]);

        return $id;
    }

    public function voidStamp(Organization $organization, User $user, int $companyId, int $documentId, string $reason): void
    {
        $row = DB::table('stamp_tax_documents')->where('company_id', $companyId)->where('id', $documentId)->first();
        abort_if($row === null, 404);
        if ($row->voided_at !== null) {
            throw ValidationException::withMessages(['reason' => 'Este documento ya fue anulado.']);
        }
        $this->automation->reverseSource($organization, $user, $companyId, 'stamp_tax', $documentId, now()->toDateString(), $reason);
        DB::table('stamp_tax_documents')->where('id', $documentId)->update(['voided_by' => $user->id, 'voided_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'fiscal.stamp_tax.voided', 'stamp_tax_documents', $documentId, ['reason' => $reason]);
    }

    /**
     * IGSS, IRTRA and INTECAP of the approved payrolls of each month, with the day they must be paid.
     *
     * @return list<array{month: string, label: string, employees: int, igss_employee_cents: int, igss_employer_cents: int, irtra_cents: int, intecap_cents: int, total_cents: int, due_on: string}>
     */
    public function employerContributions(int $companyId, int $year): array
    {
        $lines = DB::table('payroll_result_lines as l')->join('payroll_results as r', 'r.id', '=', 'l.payroll_result_id')->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')
            ->where('p.company_id', $companyId)->whereIn('p.status', ['approved', 'paid', 'closed'])->whereYear('p.ends_on', $year)
            ->whereIn('l.concept_code', ['IGSS_LABORAL', 'IGSS_PATRONAL', 'IRTRA', 'INTECAP'])
            ->selectRaw("TO_CHAR(p.ends_on, 'YYYY-MM') as month, l.concept_code, CAST(SUM(l.amount_cents) AS BIGINT) as total, COUNT(DISTINCT r.payroll_employee_id) as employees")
            ->groupBy('month', 'l.concept_code')->get()->groupBy('month');
        $rows = [];
        foreach ($lines as $month => $concepts) {
            $amount = fn (string $code): int => (int) ($concepts->firstWhere('concept_code', $code)->total ?? 0);
            $row = ['month' => (string) $month, 'label' => self::MONTHS[(int) substr((string) $month, 5, 2) - 1].' '.substr((string) $month, 0, 4), 'employees' => (int) $concepts->max('employees'),
                'igss_employee_cents' => $amount('IGSS_LABORAL'), 'igss_employer_cents' => $amount('IGSS_PATRONAL'), 'irtra_cents' => $amount('IRTRA'), 'intecap_cents' => $amount('INTECAP'),
                'total_cents' => 0, 'due_on' => Carbon::parse($month.'-01')->addMonth()->setDay(20)->toDateString()];
            $row['total_cents'] = $row['igss_employee_cents'] + $row['igss_employer_cents'] + $row['irtra_cents'] + $row['intecap_cents'];
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['month'], $b['month']));

        return $rows;
    }

    /**
     * The year's ISO by quarter and its credit against the quarterly ISR payments: what is paid as ISO can be credited
     * against the ISR of the same year until it runs out, so each quarter shows the ISR left to pay.
     *
     * @return array{estimate: array<string, mixed>, quarters: list<array{quarter: int, due_on: string, iso_cents: int, isr_cents: int, credit_used_cents: int, isr_to_pay_cents: int, credit_left_cents: int}>}
     */
    public function iso(int $companyId, int $year): array
    {
        $estimate = $this->iso->estimate($companyId, $year);
        $isrQuarters = collect((array) ($this->isr->calculate($companyId, $year)['quarters'] ?? []))->keyBy('quarter');
        $credit = 0;
        $quarters = [];
        foreach (range(1, 4) as $quarter) {
            $iso = (int) $estimate['quarterly_cents'];
            $credit += $iso;
            $isrDue = (int) ($isrQuarters->get($quarter)['payment_cents'] ?? 0);
            $used = min($credit, $isrDue);
            $credit -= $used;
            $quarters[] = ['quarter' => $quarter, 'due_on' => Carbon::create($year, $quarter * 3, 1)->addMonth()->endOfMonth()->toDateString(), 'iso_cents' => $iso,
                'isr_cents' => $isrDue, 'credit_used_cents' => $used, 'isr_to_pay_cents' => $isrDue - $used, 'credit_left_cents' => $credit];
        }

        return ['estimate' => $estimate, 'quarters' => $quarters];
    }

    /** Drafts the ISO of one quarter: ISO to credit against ISO payable. */
    public function draftIso(Organization $organization, User $user, int $companyId, int $year, int $quarter): int
    {
        $amount = (int) $this->iso->estimate($companyId, $year)['quarterly_cents'];
        if ($amount <= 0) {
            throw ValidationException::withMessages(['quarter' => 'No hay ISO calculado para ese año: revisa el balance del año anterior.']);
        }

        return $this->draft($organization, $user, $companyId, 'iso_quarter', $year * 10 + $quarter, Carbon::create($year, $quarter * 3, 1)->endOfMonth()->toDateString(),
            'ISO trimestre '.$quarter.' de '.$year, '1305', '2208', $amount);
    }

    private function draft(Organization $organization, User $user, int $companyId, string $sourceType, int $sourceId, string $date, string $description, string $debitCode, string $creditCode, int $cents): int
    {
        $this->accounting->installDefaultChart($companyId);
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('code', [$debitCode, $creditCode])->where('is_active', true)->pluck('id', 'code');
        if (! isset($accounts[$debitCode], $accounts[$creditCode])) {
            throw ValidationException::withMessages(['accounting' => "Activa las cuentas {$debitCode} y {$creditCode} del catálogo para registrar este impuesto."]);
        }
        $amount = number_format($cents / 100, 2, '.', '');
        $entry = $this->automation->draft($organization, $user, $companyId, $sourceType, $sourceId, $date, $description, strtoupper($sourceType).'-'.$sourceId, [
            ['account_id' => (int) $accounts[$debitCode], 'debit' => $amount, 'credit' => '0', 'description' => $description],
            ['account_id' => (int) $accounts[$creditCode], 'debit' => '0', 'credit' => $amount, 'description' => $description],
        ]);
        if ($entry === null) {
            throw ValidationException::withMessages(['accounting' => 'Abre un período contable que incluya el '.Carbon::parse($date)->format('d/m/Y').'.']);
        }

        return $entry;
    }
}
