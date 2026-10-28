<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Consolidated financial statements of a group of companies (NIIF 10): the accounts of the parent and its subsidiaries
 * added line by line by account code, less what they did among themselves, which the group did not do with anyone:
 *  - what one company owes another (its receivable against the other's payable);
 *  - the sales from one to another in the period, against the purchases or expenses the other recorded for them;
 *  - optionally the parent's investment in each subsidiary against that subsidiary's capital.
 * The part of each subsidiary that the parent does not own is shown as the non-controlling interest.
 *
 * @phpstan-type Line array{code: string, name: string, type: string, companies: array<int, int>, eliminations: int, consolidated: int}
 */
class ConsolidationService
{
    private const SECTIONS = ['asset' => 'Activo', 'liability' => 'Pasivo', 'equity' => 'Patrimonio', 'income' => 'Ingresos', 'expense' => 'Gastos'];

    private const RESULT = '3999-RESULTADO';

    public function __construct(private AccountingService $accounting, private FinancialStatementService $statements) {}

    /** @return list<array{id: int, name: string, parent: string, members: int}> */
    public function groups(Organization $organization): array
    {
        return array_values(DB::table('company_groups as g')->join('companies as c', 'c.id', '=', 'g.parent_company_id')->where('g.organization_id', $organization->id)->orderBy('g.name')
            ->get(['g.id', 'g.name', 'c.name as parent'])->map(fn (stdClass $group): array => ['id' => (int) $group->id, 'name' => (string) $group->name, 'parent' => (string) $group->parent,
                'members' => DB::table('company_group_members')->where('company_group_id', $group->id)->count() + 1])->all());
    }

    public function find(Organization $organization, int $groupId): stdClass
    {
        $group = DB::table('company_groups')->where('organization_id', $organization->id)->where('id', $groupId)->first();
        abort_if($group === null, 404);

        return $group;
    }

    /**
     * @param  list<int>  $allowed  companies the person can see
     * @param  list<array{company_id: int, ownership_bp: int}>  $members
     */
    public function save(Organization $organization, User $user, ?int $groupId, string $name, int $parentId, array $members, ?string $investmentCode, array $allowed): int
    {
        $ids = [$parentId, ...array_column($members, 'company_id')];
        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['members' => 'Elige empresas distintas entre las que tienes acceso; la controladora no se repite como subsidiaria.']);
        }
        if ($members === []) {
            throw ValidationException::withMessages(['members' => 'Un grupo necesita al menos una subsidiaria.']);
        }
        $values = ['name' => $name, 'parent_company_id' => $parentId, 'investment_account_code' => $investmentCode, 'updated_at' => now()];
        if ($groupId === null) {
            $groupId = DB::table('company_groups')->insertGetId([...$values, 'organization_id' => $organization->id, 'created_by' => $user->id, 'created_at' => now()]);
        } else {
            $this->find($organization, $groupId);
            DB::table('company_groups')->where('id', $groupId)->update($values);
            DB::table('company_group_members')->where('company_group_id', $groupId)->delete();
        }
        DB::table('company_group_members')->insert(array_map(fn (array $member): array => ['organization_id' => $organization->id, 'company_group_id' => $groupId, 'company_id' => $member['company_id'],
            'ownership_bp' => $member['ownership_bp'], 'created_at' => now(), 'updated_at' => now()], $members));
        $this->accounting->audit($organization, $user, 'consolidation.group.saved', 'company_groups', $groupId, ['members' => count($members)]);

        return $groupId;
    }

    /**
     * @return array{group: array{id: int, name: string}, from: string, to: string, companies: list<array{id: int, name: string, ownership_bp: int}>, sections: array<string, array{label: string, lines: list<Line>, total: int}>, non_controlling: array{equity_cents: int, result_cents: int}, result_cents: int, balanced: bool, difference_cents: int, warnings: list<string>}
     */
    public function statement(stdClass $group, string $from, string $to): array
    {
        $companies = collect([['id' => (int) $group->parent_company_id, 'ownership_bp' => 10000]])
            ->concat(DB::table('company_group_members')->where('company_group_id', $group->id)->get(['company_id', 'ownership_bp'])->map(fn (stdClass $m): array => ['id' => (int) $m->company_id, 'ownership_bp' => (int) $m->ownership_bp]))
            ->map(fn (array $c): array => [...$c, ...(array) DB::table('companies')->where('id', $c['id'])->first(['name', 'tax_id'])])->values();
        $lines = [];
        $add = function (string $code, string $name, string $type, int $companyId, int $cents) use (&$lines): void {
            $lines[$code] ??= ['code' => $code, 'name' => $name, 'type' => $type, 'companies' => [], 'eliminations' => 0, 'consolidated' => 0];
            $lines[$code]['companies'][$companyId] = ($lines[$code]['companies'][$companyId] ?? 0) + $cents;
        };
        foreach ($companies as $company) {
            foreach ($this->statements->balances($company['id'], $to) as $row) {
                if (in_array($row['type'], ['asset', 'liability', 'equity'], true)) {
                    $add($row['code'], $row['name'], $row['type'], $company['id'], $row['cents']);
                } else {
                    // Results not yet closed into equity: the balance sheet carries them as one equity line.
                    $add(self::RESULT, 'Resultados acumulados y del ejercicio', 'equity', $company['id'], $row['type'] === 'income' ? $row['cents'] : -$row['cents']);
                }
            }
            foreach ($this->statements->balances($company['id'], $to, $from) as $row) {
                if (in_array($row['type'], ['income', 'expense'], true)) {
                    $add($row['code'], $row['name'], $row['type'], $company['id'], $row['cents']);
                }
            }
        }
        $warnings = [];
        $eliminate = function (int $companyId, ?int $accountId, int $cents) use (&$lines): void {
            $code = $accountId === null ? null : DB::table('ledger_accounts')->where('id', $accountId)->value('code');
            if ($code === null || ! isset($lines[$code]) || $cents === 0) {
                return;
            }
            $lines[$code]['eliminations'] -= $cents;
            // Taking income or expense out of the period also takes it out of the result the balance sheet carries.
            if (isset($lines[self::RESULT]) && in_array($lines[$code]['type'], ['income', 'expense'], true)) {
                $lines[self::RESULT]['eliminations'] += $lines[$code]['type'] === 'income' ? -$cents : $cents;
            }
        };
        foreach ($companies as $seller) {
            $sellerMap = DB::table('company_accounting_mappings')->where('company_id', $seller['id'])->first();
            foreach ($companies as $buyer) {
                if ($seller['id'] === $buyer['id'] || blank($buyer['tax_id'])) {
                    continue;
                }
                $buyerMap = DB::table('company_accounting_mappings')->where('company_id', $buyer['id'])->first();
                // What the buyer still owes the seller at the cut-off, as the seller's receivable.
                $owed = $this->receivableFrom($seller['id'], (string) $buyer['tax_id'], $to);
                $payable = blank($seller['tax_id']) ? 0 : $this->payableTo($buyer['id'], (string) $seller['tax_id'], $to);
                if ($owed !== 0 || $payable !== 0) {
                    $eliminate($seller['id'], $sellerMap?->receivable_account_id, $owed);
                    $eliminate($buyer['id'], $buyerMap?->payable_account_id, $payable);
                    if ($owed !== $payable) {
                        $warnings[] = $seller['name'].' tiene por cobrar a '.$buyer['name'].' Q '.number_format($owed / 100, 2).', pero '.$buyer['name'].' registra por pagar Q '.number_format($payable / 100, 2).'. Concilia la diferencia.';
                    }
                }
                // The sales between them in the period, without VAT, against what the buyer recorded for those purchases.
                $sales = (int) DB::table('sales_invoices')->where('company_id', $seller['id'])->where('status', 'issued')->where('customer_tax_id', $buyer['tax_id'])
                    ->whereBetween('issued_on', [$from, $to])->sum(DB::raw('total_cents - vat_cents'));
                $eliminate($seller['id'], $sellerMap?->revenue_account_id, $sales);
                if (! blank($seller['tax_id'])) {
                    $purchases = DB::table('purchase_document_lines as l')->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')->where('d.company_id', $buyer['id'])
                        ->where('d.status', 'approved')->where('d.supplier_tax_id', $seller['tax_id'])->whereBetween('d.document_date', [$from, $to])
                        ->groupBy('l.ledger_account_id')->selectRaw('l.ledger_account_id, CAST(SUM(ROUND(l.unit_cents * l.quantity) - l.vat_cents) AS BIGINT) as net')->pluck('net', 'ledger_account_id');
                    foreach ($purchases as $accountId => $net) {
                        $eliminate($buyer['id'], (int) $accountId, (int) $net);
                    }
                    if ($sales !== (int) $purchases->sum()) {
                        $warnings[] = 'Ventas de '.$seller['name'].' a '.$buyer['name'].' por Q '.number_format($sales / 100, 2).' contra compras registradas por Q '.number_format((int) $purchases->sum() / 100, 2).'.';
                    }
                }
            }
        }

        // The parent's investment in each subsidiary against the subsidiary's capital (3101), when the group says where it is.
        $investmentCode = $group->investment_account_code ?? null;
        if (filled($investmentCode) && isset($lines[$investmentCode])) {
            $investment = (int) ($lines[$investmentCode]['companies'][(int) $group->parent_company_id] ?? 0);
            $capital = 0;
            foreach ($companies->skip(1) as $subsidiary) {
                $capital += intdiv((int) ($lines['3101']['companies'][$subsidiary['id']] ?? 0) * $subsidiary['ownership_bp'], 10000);
            }
            $lines[$investmentCode]['eliminations'] -= $investment;
            if (isset($lines['3101'])) {
                $lines['3101']['eliminations'] -= $capital;
            }
            if ($investment !== $capital) {
                $warnings[] = 'La inversión de la controladora (Q '.number_format($investment / 100, 2).') difiere de su parte del capital de las subsidiarias (Q '.number_format($capital / 100, 2).'): la diferencia es plusvalía o un ajuste por revisar.';
            }
        }

        // Non-controlling interest: the share of each subsidiary's equity and result that the parent does not own.
        $nciEquity = 0;
        $nciResult = 0;
        foreach ($companies->skip(1) as $subsidiary) {
            $minority = 10000 - $subsidiary['ownership_bp'];
            if ($minority <= 0) {
                continue;
            }
            $equity = 0;
            $result = 0;
            foreach ($lines as $line) {
                $cents = (int) ($line['companies'][$subsidiary['id']] ?? 0);
                $equity += $line['type'] === 'equity' ? $cents : 0;
                $result += $line['type'] === 'income' ? $cents : ($line['type'] === 'expense' ? -$cents : 0);
            }
            $nciEquity += intdiv($equity * $minority, 10000);
            $nciResult += intdiv($result * $minority, 10000);
        }

        $sections = [];
        foreach (self::SECTIONS as $type => $label) {
            $rows = array_values(array_map(function (array $line): array {
                $line['consolidated'] = (int) array_sum($line['companies']) + $line['eliminations'];

                return $line;
            }, array_filter($lines, fn (array $line): bool => $line['type'] === $type)));
            usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
            $sections[$type] = ['label' => $label, 'lines' => $rows, 'total' => (int) array_sum(array_column($rows, 'consolidated'))];
        }
        $result = $sections['income']['total'] - $sections['expense']['total'];
        // The equity section already carries the results not closed, so the balance sheet must square on its own.
        $difference = $sections['asset']['total'] - ($sections['liability']['total'] + $sections['equity']['total']);

        return ['group' => ['id' => (int) $group->id, 'name' => (string) $group->name], 'from' => $from, 'to' => $to,
            'companies' => array_values($companies->map(fn (array $c): array => ['id' => $c['id'], 'name' => (string) $c['name'], 'ownership_bp' => $c['ownership_bp']])->all()),
            'sections' => $sections, 'non_controlling' => ['equity_cents' => $nciEquity, 'result_cents' => $nciResult], 'result_cents' => $result,
            'balanced' => $difference === 0, 'difference_cents' => $difference, 'warnings' => $warnings];
    }

    private function receivableFrom(int $companyId, string $taxId, string $to): int
    {
        return (int) DB::table('sales_invoices as i')->where('i.company_id', $companyId)->where('i.status', 'issued')->where('i.customer_tax_id', $taxId)->where('i.issued_on', '<=', $to)
            ->selectRaw("CAST(COALESCE(SUM(i.total_cents - COALESCE((SELECT SUM(r.amount_cents) FROM sales_receipts r WHERE r.sales_invoice_id = i.id AND r.voided_at IS NULL AND r.received_on <= ?), 0)
                - COALESCE((SELECT SUM(c.amount_cents) FROM sales_credit_notes c WHERE c.sales_invoice_id = i.id AND c.status = 'issued' AND c.issued_on <= ?), 0)), 0) AS BIGINT) as owed", [$to, $to])->value('owed');
    }

    private function payableTo(int $companyId, string $taxId, string $to): int
    {
        return (int) DB::table('purchase_documents as d')->where('d.company_id', $companyId)->where('d.status', 'approved')->where('d.supplier_tax_id', $taxId)->where('d.document_date', '<=', $to)
            ->selectRaw("CAST(COALESCE(SUM(d.total_cents - COALESCE((SELECT SUM(p.amount_cents) FROM purchase_payments p WHERE p.purchase_document_id = d.id AND p.voided_at IS NULL AND p.paid_on <= ?), 0)
                - COALESCE((SELECT SUM(c.amount_cents) FROM purchase_credit_notes c WHERE c.purchase_document_id = d.id AND c.status = 'issued' AND c.issued_on <= ?), 0)), 0) AS BIGINT) as owed", [$to, $to])->value('owed');
    }
}
