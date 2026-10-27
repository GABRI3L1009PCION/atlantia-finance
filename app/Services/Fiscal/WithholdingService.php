<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

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

    /** Withholdings the company's customers make on its invoices: an asset to credit, not money in the bank. */
    private const RECEIVED_ACCOUNTS = ['iva' => ['1303', 'Retenciones de IVA soportadas'], 'isr' => ['1304', 'ISR retenido por clientes']];

    /**
     * IVA withholding agents (Ley de Retenciones del IVA, Decreto 20-2006) with the share of the invoice's VAT they
     * usually withhold. They are suggestions the accountant can change: the agent's resolution from the SAT decides.
     */
    public const VAT_AGENTS = [
        'special_taxpayer' => ['Contribuyente especial', 1500],
        'exporter' => ['Exportador', 1500],
        'agricultural_exporter' => ['Exportador de productos agropecuarios, artesanales o textiles', 6500],
        'public_sector' => ['Sector público', 2500],
        'card_operator' => ['Operador de tarjetas de crédito o débito', 1500],
        'other' => ['Otro agente designado por la SAT', 1500],
    ];

    /** A pequeño contribuyente's single 5 % tax is withheld in full by the agents that pay it (Decreto 4-2012). */
    private const SMALL_TAXPAYER_RATE_BP = 500;

    /** Régimen opcional simplificado: 5 % on the first Q30,000 of the invoice without VAT and 7 % on the excess (Decreto 10-2012). */
    private const ISR_SIMPLIFIED = ['limit_cents' => 3000000, 'low_bp' => 500, 'high_bp' => 700];

    public const ISR_REGIMES = ['simplified' => 'Sujeto a retención definitiva (opcional simplificado)', 'utilities' => 'Sujeto a pagos trimestrales (sobre utilidades)', 'exempt' => 'Exento o no afecto'];

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

    /**
     * @return array{vat_agent: string|null, vat_rate_bp: int|null, isr_agent: bool, threshold_cents: int}
     */
    public function settings(int $companyId): array
    {
        $profile = DB::table('company_fiscal_profiles')->where('company_id', $companyId)->first(['vat_withholding_agent', 'vat_withholding_rate_bp', 'isr_withholding_agent', 'withholding_threshold_cents']);

        return ['vat_agent' => $profile?->vat_withholding_agent, 'vat_rate_bp' => $profile?->vat_withholding_rate_bp === null ? null : (int) $profile->vat_withholding_rate_bp,
            'isr_agent' => (bool) ($profile->isr_withholding_agent ?? false), 'threshold_cents' => (int) ($profile->withholding_threshold_cents ?? 250000)];
    }

    /**
     * @param  array{vat_agent: string|null, vat_rate_bp: int|null, isr_agent: bool, threshold_cents: int}  $data
     */
    public function saveSettings(Organization $organization, User $user, int $companyId, array $data): void
    {
        if (! DB::table('company_fiscal_profiles')->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages(['vat_agent' => 'Configura primero el perfil fiscal de la empresa.']);
        }
        $rate = $data['vat_agent'] === null ? null : ($data['vat_rate_bp'] ?? self::VAT_AGENTS[$data['vat_agent']][1]);
        DB::table('company_fiscal_profiles')->where('company_id', $companyId)->update(['vat_withholding_agent' => $data['vat_agent'], 'vat_withholding_rate_bp' => $rate,
            'isr_withholding_agent' => $data['isr_agent'], 'withholding_threshold_cents' => $data['threshold_cents'], 'updated_by' => $user->id, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'fiscal.withholding_agent.updated', 'companies', $companyId, ['vat_agent' => $data['vat_agent'], 'vat_rate_bp' => $rate, 'isr_agent' => $data['isr_agent']]);
    }

    /**
     * What the law asks to withhold when this purchase is paid in full: IVA when the company is an IVA agent (a share of
     * the VAT, or 5 % of the total of a pequeño contribuyente), ISR when it is an ISR agent and the supplier is under the
     * optional simplified regime. Below the minimum amount nothing is suggested. The payment scales the bases to the
     * part being paid.
     *
     * @return list<array{kind: string, base_cents: int, rate_bp: int, amount_cents: int, reason: string}>
     */
    public function suggest(int $companyId, stdClass $document): array
    {
        $settings = $this->settings($companyId);
        $total = (int) $document->total_cents;
        if ($total < $settings['threshold_cents'] || ($document->fiscal_document_type ?? null) === 'FESP') {
            return [];
        }
        $suggestions = [];
        $small = (int) ($document->small_taxpayer_cents ?? 0);
        $vat = (int) ($document->vat_cents ?? 0);
        if ($settings['vat_agent'] !== null && $small > 0) {
            $suggestions[] = ['kind' => 'iva', 'base_cents' => $small, 'rate_bp' => self::SMALL_TAXPAYER_RATE_BP, 'amount_cents' => (int) round($small * self::SMALL_TAXPAYER_RATE_BP / 10000),
                'reason' => 'Pequeño contribuyente: se le retiene el 5 % del total de la factura.'];
        } elseif ($settings['vat_agent'] !== null && $vat > 0) {
            $rate = (int) ($settings['vat_rate_bp'] ?? self::VAT_AGENTS[$settings['vat_agent']][1]);
            $suggestions[] = ['kind' => 'iva', 'base_cents' => $vat, 'rate_bp' => $rate, 'amount_cents' => (int) round($vat * $rate / 10000),
                'reason' => self::VAT_AGENTS[$settings['vat_agent']][0].': retiene el '.number_format($rate / 100, 2).' % del IVA de la factura.'];
        }
        $regime = $document->purchase_supplier_id === null ? null : DB::table('purchase_suppliers')->where('id', $document->purchase_supplier_id)->value('isr_regime');
        if ($settings['isr_agent'] && $regime === 'simplified' && $small === 0) {
            $base = $total - $vat;
            $amount = (int) round(min($base, self::ISR_SIMPLIFIED['limit_cents']) * self::ISR_SIMPLIFIED['low_bp'] / 10000 + max(0, $base - self::ISR_SIMPLIFIED['limit_cents']) * self::ISR_SIMPLIFIED['high_bp'] / 10000);
            $suggestions[] = ['kind' => 'isr', 'base_cents' => $base, 'rate_bp' => (int) round($amount * 10000 / max(1, $base)), 'amount_cents' => $amount,
                'reason' => 'Proveedor sujeto a retención definitiva: 5 % hasta Q30,000 sin IVA y 7 % sobre el excedente.'];
        }

        return $suggestions;
    }

    /**
     * Records the constancia a customer handed over for the withholding it made on one of the company's invoices: it
     * settles that part of the invoice like a receipt, and its entry debits the withholding to credit instead of a bank.
     */
    public function receive(Organization $organization, User $user, int $companyId, stdClass $invoice, string $kind, int $amountCents, string $certificateNumber, string $certificateDate, string $requestId): int
    {
        $accountId = $this->ensureReceivedAccounts($companyId)[$kind];
        $id = DB::table('sales_receipts')->insertGetId(['public_id' => $requestId, 'sales_invoice_id' => $invoice->id, 'amount_cents' => $amountCents, 'received_on' => $certificateDate,
            'reference' => 'Constancia '.$certificateNumber, 'withholding_kind' => $kind, 'certificate_number' => $certificateNumber, 'certificate_date' => $certificateDate,
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $dimensions = ['third_party_type' => 'customer', 'third_party_id' => $invoice->sales_customer_id];
        $description = (self::KINDS[$kind] ?? $kind).' por '.$invoice->customer_name;
        [$debit, $credit] = $this->automation->ruleLines($companyId, 'sales_receipt.origin', $amountCents, ['description' => $description, ...$dimensions]);
        $debit['account_id'] = $accountId;
        $entry = $this->automation->draft($organization, $user, $companyId, 'sales_receipt', $id, $certificateDate, $description, 'RET-'.$certificateNumber, [$debit, $credit], 'sales_receipt.origin');
        if ($entry === null) {
            throw ValidationException::withMessages(['certificate_date' => 'Abre un período contable que incluya la fecha de la constancia.']);
        }
        $this->accounting->audit($organization, $user, 'sales.withholding.received', 'sales_receipts', $id, ['kind' => $kind, 'amount_cents' => $amountCents, 'certificate' => $certificateNumber]);

        return $id;
    }

    /** Stores the number and date of the SAT constancia (Retenciones Web) of a withholding the company made. */
    public function setCertificate(Organization $organization, User $user, int $companyId, int $withholdingId, string $number, string $date): void
    {
        $updated = DB::table('purchase_payment_withholdings')->where('company_id', $companyId)->where('id', $withholdingId)
            ->update(['certificate_number' => mb_substr(trim($number), 0, 60), 'certificate_date' => $date, 'updated_at' => now()]);
        abort_if($updated === 0, 404);
        $this->accounting->audit($organization, $user, 'fiscal.withholding.certificate', 'purchase_payment_withholdings', $withholdingId, ['number' => $number, 'date' => $date]);
    }

    /**
     * Withholdings against the ledger in a range: what was withheld from suppliers against what was credited to the
     * accounts payable to SAT, what customers withheld against what was debited to the accounts to credit, and the
     * withholdings that still lack their SAT constancia.
     *
     * @return array{made: list<array{kind: string, label: string, documents_cents: int, ledger_cents: int, difference_cents: int}>, received: list<array{kind: string, label: string, documents_cents: int, ledger_cents: int, difference_cents: int}>, missing_certificates: int, received_rows: list<array<string, mixed>>}
     */
    public function reconciliation(int $companyId, string $from, string $to): array
    {
        $made = DB::table('purchase_payment_withholdings as w')->join('purchase_payments as p', 'p.id', '=', 'w.purchase_payment_id')->where('w.company_id', $companyId)
            ->whereNull('p.voided_at')->whereBetween('p.paid_on', [$from, $to])->groupBy('w.kind')->selectRaw('w.kind, CAST(SUM(w.amount_cents) AS BIGINT) as total')->pluck('total', 'kind');
        $receivedRows = DB::table('sales_receipts as r')->join('sales_invoices as i', 'i.id', '=', 'r.sales_invoice_id')->where('i.company_id', $companyId)->whereNotNull('r.withholding_kind')
            ->whereNull('r.voided_at')->whereBetween('r.received_on', [$from, $to])->orderBy('r.received_on')
            ->get(['r.id', 'r.withholding_kind', 'r.amount_cents', 'r.certificate_number', 'r.certificate_date', 'i.customer_name', 'i.customer_tax_id', 'i.fiscal_series', 'i.fiscal_number', 'i.number']);
        $line = function (string $kind, string $label, int $documents, int $ledger): array {
            return ['kind' => $kind, 'label' => $label, 'documents_cents' => $documents, 'ledger_cents' => $ledger, 'difference_cents' => $documents - $ledger];
        };

        return [
            'made' => [
                $line('iva', 'IVA retenido a proveedores', (int) ($made['iva'] ?? 0), $this->ledgerMovement($companyId, self::ACCOUNTS['iva'][0], 'credit', $from, $to)),
                $line('isr', 'ISR retenido a proveedores', (int) ($made['isr'] ?? 0), $this->ledgerMovement($companyId, self::ACCOUNTS['isr'][0], 'credit', $from, $to)),
            ],
            'received' => [
                $line('iva', 'IVA que nos retuvieron', (int) $receivedRows->where('withholding_kind', 'iva')->sum('amount_cents'), $this->ledgerMovement($companyId, self::RECEIVED_ACCOUNTS['iva'][0], 'debit', $from, $to)),
                $line('isr', 'ISR que nos retuvieron', (int) $receivedRows->where('withholding_kind', 'isr')->sum('amount_cents'), $this->ledgerMovement($companyId, self::RECEIVED_ACCOUNTS['isr'][0], 'debit', $from, $to)),
            ],
            'missing_certificates' => DB::table('purchase_payment_withholdings as w')->join('purchase_payments as p', 'p.id', '=', 'w.purchase_payment_id')->where('w.company_id', $companyId)
                ->whereNull('p.voided_at')->whereBetween('p.paid_on', [$from, $to])->whereNull('w.certificate_number')->count(),
            'received_rows' => array_values($receivedRows->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'kind' => (string) $row->withholding_kind, 'kind_label' => self::KINDS[$row->withholding_kind] ?? $row->withholding_kind,
                'customer' => (string) $row->customer_name, 'tax_id' => $row->customer_tax_id, 'document' => trim(($row->fiscal_series ?? '').' '.($row->fiscal_number ?? '')) ?: '#'.$row->number,
                'amount_cents' => (int) $row->amount_cents, 'certificate_number' => (string) $row->certificate_number, 'certificate_date' => (string) $row->certificate_date])->all()),
        ];
    }

    /** Posted movement of one account in a range (credits or debits), to set against the documents. */
    private function ledgerMovement(int $companyId, string $code, string $side, string $from, string $to): int
    {
        return (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.company_id', $companyId)->where('a.code', $code)->whereIn('e.status', ['posted', 'reversed'])->whereBetween('e.entry_date', [$from, $to])
            ->sum($side === 'credit' ? 'l.credit_cents' : 'l.debit_cents');
    }

    /** @return array{iva: int, isr: int} */
    private function ensureReceivedAccounts(int $companyId): array
    {
        $this->accounting->installDefaultChart($companyId);
        $ids = ['iva' => 0, 'isr' => 0];
        foreach (self::RECEIVED_ACCOUNTS as $kind => [$code, $name]) {
            DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => 'asset', 'is_active' => true, 'accepts_movements' => true,
                'is_group' => false, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->first(['id', 'type', 'is_active', 'accepts_movements', 'is_group']);
            if ($account === null || $account->type !== 'asset' || ! $account->is_active || ! $account->accepts_movements || $account->is_group) {
                throw ValidationException::withMessages(['accounting' => 'La cuenta '.$code.' ('.$name.') ya existe en el catálogo con otro uso.']);
            }
            $ids[$kind] = (int) $account->id;
        }

        return $ids;
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
            ->get(['w.id', 'w.kind', 'w.base_cents', 'w.rate_bp', 'w.amount_cents', 'w.certificate_number', 'w.certificate_date', 'p.paid_on', 'p.reference', 'd.supplier_name', 'd.supplier_tax_id', 'd.fiscal_series', 'd.fiscal_number']);
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
                'rate_bp' => (int) $row->rate_bp, 'amount_cents' => (int) $row->amount_cents, 'certificate_number' => $row->certificate_number, 'certificate_date' => $row->certificate_date];
        }
        ksort($monthly);

        return ['rows' => $list, 'totals' => $totals, 'monthly' => array_values($monthly)];
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
