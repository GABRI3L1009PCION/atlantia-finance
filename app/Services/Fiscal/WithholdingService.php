<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withholdings (ISR and IVA) applied when a supplier is paid. The payment applies its full amount to the invoice; the
 * company pays the supplier the net and owes the withheld taxes to SAT, so the entry is: debit suppliers (gross),
 * credit bank (net), credit withholdings payable. Net paid plus withholdings always equals the amount applied.
 */
class WithholdingService
{
    public const KINDS = ['isr' => 'ISR retenido', 'iva' => 'IVA retenido'];

    /** Suggested rates in basis points; the user may use others according to the supplier's situation. */
    public const SUGGESTED_RATES = ['isr' => 500, 'iva' => 1500];

    private const ACCOUNTS = ['isr' => ['2203', 'ISR retenido por pagar'], 'iva' => ['2204', 'IVA retenido por pagar']];

    public function __construct(private AccountingService $accounting, private AccountingAutomationService $automation) {}

    /**
     * @param  list<array{kind: string, base: string, rate: string, certificate_number?: string|null}>  $items
     * @return list<array{kind: string, base_cents: int, rate_bp: int, amount_cents: int, certificate_number: ?string}>
     */
    public function compute(array $items, int $grossCents): array
    {
        $computed = [];
        $seen = [];
        foreach ($items as $item) {
            if (! isset(self::KINDS[$item['kind']]) || isset($seen[$item['kind']])) {
                throw ValidationException::withMessages(['withholdings' => 'Registra a lo sumo una retención de ISR y una de IVA por pago.']);
            }
            $seen[$item['kind']] = true;
            $base = $this->accounting->cents($item['base']);
            $rate = (int) round(((float) $item['rate']) * 100);
            if ($base <= 0 || $rate < 1 || $rate > 10000) {
                throw ValidationException::withMessages(['withholdings' => 'Indica la base y una tasa de retención entre 0.01 % y 100 %.']);
            }
            $computed[] = ['kind' => $item['kind'], 'base_cents' => $base, 'rate_bp' => $rate, 'amount_cents' => (int) round($base * $rate / 10000),
                'certificate_number' => filled($item['certificate_number'] ?? null) ? mb_substr(trim((string) $item['certificate_number']), 0, 60) : null];
        }
        $withheld = array_sum(array_column($computed, 'amount_cents'));
        if ($withheld >= $grossCents) {
            throw ValidationException::withMessages(['withholdings' => 'Las retenciones no pueden igualar ni superar el monto aplicado al pago.']);
        }
        if (in_array(0, array_column($computed, 'amount_cents'), true)) {
            throw ValidationException::withMessages(['withholdings' => 'Una retención resultó en cero; revisa la base y la tasa.']);
        }

        return $computed;
    }

    /**
     * Records the withholdings of a payment and drafts its journal entry.
     *
     * @param  list<array{kind: string, base_cents: int, rate_bp: int, amount_cents: int, certificate_number: ?string}>  $withholdings
     * @param  array<string, mixed>  $dimensions
     */
    public function draftPayment(Organization $organization, User $user, int $companyId, int $paymentId, string $paidOn, string $description, string $reference, int $grossCents, array $withholdings, array $dimensions): ?int
    {
        $accounts = $this->ensureAccounts($companyId);
        [$debit, $credit] = $this->automation->ruleLines($companyId, 'purchase_payment.origin', $grossCents, ['description' => $description, ...$dimensions]);
        $withheld = (int) array_sum(array_column($withholdings, 'amount_cents'));
        $credit['credit'] = $this->decimal($grossCents - $withheld);
        $lines = [$debit, $credit];
        foreach ($withholdings as $item) {
            DB::table('purchase_payment_withholdings')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'purchase_payment_id' => $paymentId, 'kind' => $item['kind'], 'base_cents' => $item['base_cents'],
                'rate_bp' => $item['rate_bp'], 'amount_cents' => $item['amount_cents'], 'ledger_account_id' => $accounts[$item['kind']], 'certificate_number' => $item['certificate_number'], 'created_at' => now(), 'updated_at' => now()]);
            $lines[] = ['account_id' => $accounts[$item['kind']], 'debit' => '0', 'credit' => $this->decimal($item['amount_cents']), 'description' => self::KINDS[$item['kind']].' '.number_format($item['rate_bp'] / 100, 2).' %',
                ...array_intersect_key($dimensions, ['third_party_type' => 1, 'third_party_id' => 1])];
        }

        return $this->automation->draft($organization, $user, $companyId, 'purchase_payment', $paymentId, $paidOn, $description, $reference, $lines, 'purchase_payment.origin');
    }

    /** @return array{isr: int, iva: int} */
    public function ensureAccounts(int $companyId): array
    {
        $this->accounting->installDefaultChart($companyId);
        $ids = ['isr' => 0, 'iva' => 0];
        foreach (self::ACCOUNTS as $kind => [$code, $name]) {
            DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => 'liability', 'is_active' => true, 'accepts_movements' => true,
                'is_group' => false, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->first(['id', 'type', 'is_active', 'accepts_movements', 'is_group']);
            if ($account === null || $account->type !== 'liability' || ! $account->is_active || ! $account->accepts_movements || $account->is_group) {
                throw ValidationException::withMessages(['accounting' => 'La cuenta '.$code.' ('.$name.') ya existe en el catálogo con otro uso.']);
            }
            $ids[$kind] = (int) $account->id;
        }

        return $ids;
    }

    /**
     * Consolidated withholdings applied in a range, with totals by kind and by month (voided payments excluded).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, monthly: list<array<string, mixed>>}
     */
    public function report(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('purchase_payment_withholdings as w')->join('purchase_payments as p', 'p.id', '=', 'w.purchase_payment_id')->join('purchase_documents as d', 'd.id', '=', 'p.purchase_document_id')
            ->leftJoin('purchase_suppliers as s', 's.id', '=', 'd.purchase_supplier_id')->where('w.company_id', $companyId)->whereNull('p.voided_at')->whereBetween('p.paid_on', [$from, $to])->orderBy('p.paid_on')->orderBy('w.id')
            ->get(['w.id', 'w.kind', 'w.base_cents', 'w.rate_bp', 'w.amount_cents', 'w.certificate_number', 'p.paid_on', 'p.reference', 'd.supplier_name', 'd.supplier_tax_id', 'd.fiscal_series', 'd.fiscal_number']);
        $list = [];
        $totals = ['isr_cents' => 0, 'iva_cents' => 0, 'total_cents' => 0, 'base_cents' => 0];
        $monthly = [];
        foreach ($rows as $row) {
            $month = substr((string) $row->paid_on, 0, 7);
            $monthly[$month] ??= ['month' => $month, 'isr_cents' => 0, 'iva_cents' => 0, 'total_cents' => 0];
            $monthly[$month][$row->kind === 'isr' ? 'isr_cents' : 'iva_cents'] += (int) $row->amount_cents;
            $monthly[$month]['total_cents'] += (int) $row->amount_cents;
            $totals[$row->kind === 'isr' ? 'isr_cents' : 'iva_cents'] += (int) $row->amount_cents;
            $totals['total_cents'] += (int) $row->amount_cents;
            $totals['base_cents'] += (int) $row->base_cents;
            $list[] = ['id' => (int) $row->id, 'date' => (string) $row->paid_on, 'supplier' => (string) $row->supplier_name, 'tax_id' => $row->supplier_tax_id, 'document' => trim(($row->fiscal_series ?? '').' '.($row->fiscal_number ?? '')) ?: '—',
                'payment_reference' => (string) $row->reference, 'kind' => (string) $row->kind, 'kind_label' => self::KINDS[$row->kind] ?? (string) $row->kind, 'base_cents' => (int) $row->base_cents,
                'rate_bp' => (int) $row->rate_bp, 'amount_cents' => (int) $row->amount_cents, 'certificate_number' => $row->certificate_number];
        }
        ksort($monthly);

        return ['rows' => $list, 'totals' => $totals, 'monthly' => array_values($monthly)];
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
