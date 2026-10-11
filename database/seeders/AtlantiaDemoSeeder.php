<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Office;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AtlantiaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanCatalogSeeder::class);

        DB::transaction(function (): void {
            $users = $this->users();
            $owner = $users['demo@atlantia.test'];
            $organization = Organization::query()->withTrashed()->firstOrNew([
                'country_code' => 'GT', 'tax_identifier' => '97655988',
            ]);
            $organization->fill([
                'name' => 'Atlantia Systems', 'legal_name' => 'Atlantia Systems',
                'currency_code' => 'GTQ', 'timezone' => 'America/Guatemala',
                'phone' => '+502 0000 0000', 'status' => 'active',
            ]);
            $organization->deleted_at = null;
            $organization->save();

            foreach ($users as $email => $user) {
                DB::table('organization_user')->updateOrInsert(
                    ['organization_id' => $organization->id, 'user_id' => $user->id],
                    ['role' => $email === 'demo@atlantia.test' ? 'owner' : 'member', 'status' => 'active',
                        'joined_at' => now(), 'invited_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
                );
            }

            $office = Office::query()->withTrashed()
                ->where('organization_id', $organization->id)
                ->where('is_primary', true)
                ->first()
                ?? Office::query()->withTrashed()->firstOrNew([
                    'organization_id' => $organization->id, 'code' => 'STC-01',
                ]);
            $office->code ??= 'STC-01';
            $office->fill([
                'name' => 'Sede Santo Tomás de Castilla', 'email' => 'demo@atlantia.test',
                'phone' => '+502 0000 0000', 'address_line_1' => 'Colonia La Repegua',
                'city' => 'Santo Tomás de Castilla', 'state' => 'Izabal', 'country_code' => 'GT',
                'timezone' => 'America/Guatemala', 'is_primary' => true, 'status' => 'active',
            ]);
            $office->deleted_at = null;
            $office->save();

            foreach ($users as $email => $user) {
                DB::table('office_user')->updateOrInsert(
                    ['office_id' => $office->id, 'user_id' => $user->id],
                    ['role' => $email === 'demo@atlantia.test' ? 'manager' : 'member', 'status' => 'active',
                        'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                );
            }

            $companyId = $this->upsert('companies', ['office_id' => $office->id, 'tax_id' => '97655988'], [
                'public_id' => (string) Str::uuid(), 'name' => 'Atlantia Systems',
                'email' => 'demo@atlantia.test', 'currency' => 'GTQ',
            ]);
            foreach ($users as $email => $user) {
                DB::table('company_user')->updateOrInsert(
                    ['company_id' => $companyId, 'user_id' => $user->id],
                    ['permission' => in_array($email, ['demo@atlantia.test', 'contabilidad@atlantia.test'], true) ? 'editor' : 'viewer'],
                );
            }

            $this->subscription($organization);
            $periodId = $this->periods($companyId);
            app(AccountingService::class)->installDefaultChart($companyId);
            $this->mappings($companyId);
            $this->sales($companyId, $owner->id);
            $this->purchases($companyId, $owner->id);
            $this->banking($companyId, $owner->id);
            $this->journal($companyId, $periodId, $owner->id);
        });
    }

    /** @return array<string, User> */
    private function users(): array
    {
        $names = [
            'demo@atlantia.test' => 'Administrador Atlantia',
            'contabilidad@atlantia.test' => 'Ana López',
            'auditoria@atlantia.test' => 'Carlos Méndez',
            'operaciones@atlantia.test' => 'María Contreras',
        ];
        $users = [];
        foreach ($names as $email => $name) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => config('demo.password')],
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $users[$email] = $user;
        }

        return $users;
    }

    private function subscription(Organization $organization): void
    {
        $priceId = (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('plans.code', 'enterprise')->where('plan_prices.billing_period', 'monthly')
            ->value('plan_prices.id');
        $subscription = Subscription::query()->firstOrNew([
            'organization_id' => $organization->id, 'provider' => 'manual',
            'provider_subscription_id' => 'demo-enterprise-'.$organization->id,
        ]);
        $subscription->fill([
            'provider_customer_id' => 'demo-'.$organization->id, 'status' => 'active',
            'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->endOfMonth(), 'cancel_at_period_end' => false,
        ])->save();
        SubscriptionItem::query()->updateOrCreate(
            ['subscription_id' => $subscription->id, 'item_type' => 'base_plan'],
            ['plan_price_id' => $priceId, 'quantity' => 1, 'provider_item_id' => 'demo-enterprise-item-'.$organization->id],
        );
    }

    private function periods(int $companyId): int
    {
        $currentId = 0;
        $anchor = CarbonImmutable::now()->startOfMonth();
        foreach (range(0, 5) as $offset) {
            $start = $anchor->subMonths($offset);
            $id = $this->upsert('accounting_periods', [
                'company_id' => $companyId, 'starts_on' => $start->toDateString(),
                'ends_on' => $start->endOfMonth()->toDateString(),
            ], ['name' => 'Período '.$start->format('Y-m'), 'closed_at' => null, 'closed_by' => null]);
            $currentId = $offset === 0 ? $id : $currentId;
        }

        return $currentId;
    }

    private function mappings(int $companyId): void
    {
        $account = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code');
        DB::table('company_accounting_mappings')->updateOrInsert(['company_id' => $companyId], [
            'cash_account_id' => $account['1101'], 'receivable_account_id' => $account['1103'],
            'revenue_account_id' => $account['4101'], 'payable_account_id' => $account['2102'],
            'default_expense_account_id' => $account['5201'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sales(int $companyId, int $userId): void
    {
        $rows = [
            ['Comercial Los Pinos, S.A.', '12345678', 'compras@lospinos.test', 1250000],
            ['Distribuidora del Caribe, S.A.', '54879621', 'contabilidad@caribe.test', 875000],
            ['Servicios Portuarios GT', 'CF', 'pagos@portuarios.test', 540000],
        ];
        foreach ($rows as $index => [$name, $taxId, $email, $total]) {
            $customerId = $this->upsert('sales_customers', ['company_id' => $companyId, 'tax_id' => $taxId], ['name' => $name, 'email' => $email]);
            $invoiceId = $this->upsert('sales_invoices', ['company_id' => $companyId, 'number' => 20260901 + $index], [
                'public_id' => (string) Str::uuid(), 'sales_customer_id' => $customerId, 'status' => 'issued',
                'issued_on' => now()->subDays(12 - $index * 3)->toDateString(), 'due_on' => now()->addDays(18 + $index * 3)->toDateString(),
                'total_cents' => $total, 'customer_name' => $name, 'customer_tax_id' => $taxId,
                'created_by' => $userId, 'issued_by' => $userId, 'issued_at' => now()->subDays(12 - $index * 3),
                ...$this->fiscalBreakdown($total, 'services', 'A1B2C3D4', (string) (3001 + $index)),
            ]);
            DB::table('sales_invoice_lines')->updateOrInsert(
                ['sales_invoice_id' => $invoiceId, 'position' => 1],
                ['description' => 'Servicios contables y financieros', 'quantity' => 1, 'unit_cents' => $total,
                    'tax_treatment' => 'services', 'vat_cents' => $this->includedVat($total)],
            );
            if ($index < 2) {
                $this->upsert('sales_receipts', ['sales_invoice_id' => $invoiceId, 'reference' => 'REC-DEMO-'.($index + 1)], [
                    'public_id' => (string) Str::uuid(), 'amount_cents' => intdiv($total, 2),
                    'received_on' => now()->subDays(3 - $index)->toDateString(), 'created_by' => $userId,
                ]);
            }
        }
    }

    private function purchases(int $companyId, int $userId): void
    {
        $expense = (int) DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', '5201')->value('id');
        $rows = [
            ['Tecnología Maya, S.A.', '77889901', 680000],
            ['Papelería Universal', '44556677', 185000],
            ['Servicios Eléctricos del Norte', '99887766', 320000],
        ];
        foreach ($rows as $index => [$name, $taxId, $total]) {
            $supplierId = $this->upsert('purchase_suppliers', ['company_id' => $companyId, 'tax_id' => $taxId], [
                'name' => $name, 'email' => 'facturacion'.($index + 1).'@proveedor.test', 'phone' => '+502 2222 100'.($index + 1),
            ]);
            $documentId = $this->upsert('purchase_documents', ['company_id' => $companyId, 'number' => 20260901 + $index], [
                'public_id' => (string) Str::uuid(), 'purchase_supplier_id' => $supplierId,
                'document_type' => $index === 0 ? 'purchase' : 'expense', 'external_reference' => 'FAC-PROV-'.($index + 101),
                'status' => 'approved', 'document_date' => now()->subDays(10 - $index * 2)->toDateString(),
                'due_on' => now()->addDays(20 + $index * 2)->toDateString(), 'total_cents' => $total,
                'supplier_name' => $name, 'supplier_tax_id' => $taxId, 'created_by' => $userId,
                'approved_by' => $userId, 'approved_at' => now()->subDays(9 - $index * 2),
                ...$this->fiscalBreakdown($total, $index === 2 ? 'services' : 'goods', 'PROV'.($index + 1), (string) (101 + $index)),
            ]);
            DB::table('purchase_document_lines')->updateOrInsert(
                ['purchase_document_id' => $documentId, 'position' => 1],
                ['ledger_account_id' => $expense, 'description' => 'Compra y gasto operativo', 'quantity' => 1, 'unit_cents' => $total,
                    'tax_treatment' => $index === 2 ? 'services' : 'goods', 'vat_cents' => $this->includedVat($total)],
            );
            if ($index === 1) {
                $this->upsert('purchase_payments', ['purchase_document_id' => $documentId, 'reference' => 'PAG-DEMO-1'], [
                    'public_id' => (string) Str::uuid(), 'amount_cents' => $total,
                    'paid_on' => now()->subDays(2)->toDateString(), 'created_by' => $userId,
                ]);
            }
        }
    }

    private function banking(int $companyId, int $userId): void
    {
        $ledgerId = (int) DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', '1102')->value('id');
        $accountId = $this->upsert('bank_accounts', ['company_id' => $companyId, 'ledger_account_id' => $ledgerId], [
            'public_id' => (string) Str::uuid(), 'bank_name' => 'Banco Industrial',
            'account_name' => 'Cuenta Monetaria Atlantia Systems', 'account_type' => 'checking',
            'last_four' => '4852', 'currency' => 'GTQ', 'bank_balance_cents' => 4845000,
            'last_reconciled_on' => now()->subMonth()->endOfMonth()->toDateString(), 'status' => 'active', 'created_by' => $userId,
        ]);
        $rows = [
            ['TRX-DEMO-001', 'Cobro de cliente Comercial Los Pinos', 'inflow', 625000, 4845000, 'matched'],
            ['TRX-DEMO-002', 'Pago a Papelería Universal', 'outflow', 185000, 4220000, 'matched'],
            ['TRX-DEMO-003', 'Comisión por manejo de cuenta', 'outflow', 7500, 4212500, 'pending'],
        ];
        foreach ($rows as $index => [$reference, $description, $flow, $amount, $balance, $status]) {
            $this->upsert('bank_transactions', ['bank_account_id' => $accountId, 'reference' => $reference], [
                'public_id' => (string) Str::uuid(), 'matched_journal_entry_id' => null,
                'transaction_date' => now()->subDays(5 - $index)->toDateString(), 'description' => $description,
                'flow' => $flow, 'amount_cents' => $amount, 'running_balance_cents' => $balance,
                'reconciliation_status' => $status, 'created_by' => $userId,
            ]);
        }
    }

    private function journal(int $companyId, int $periodId, int $userId): void
    {
        $account = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code');
        $rows = [
            ['Capital inicial demo', 25000000, '1102', '3101'],
            ['Venta de servicios demo', 1250000, '1103', '4101'],
            ['Gastos administrativos demo', 680000, '5201', '2102'],
        ];
        foreach ($rows as [$description, $amount, $debit, $credit]) {
            if (DB::table('journal_entries')->where('accounting_period_id', $periodId)->where('description', $description)->exists()) {
                continue;
            }
            $entryId = DB::table('journal_entries')->insertGetId([
                'public_id' => (string) Str::uuid(), 'accounting_period_id' => $periodId,
                'entry_date' => now()->toDateString(), 'description' => $description, 'kind' => 'standard',
                'status' => 'draft', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('journal_lines')->insert([
                ['journal_entry_id' => $entryId, 'position' => 1, 'ledger_account_id' => $account[$debit], 'debit_cents' => $amount, 'credit_cents' => 0],
                ['journal_entry_id' => $entryId, 'position' => 2, 'ledger_account_id' => $account[$credit], 'debit_cents' => 0, 'credit_cents' => $amount],
            ]);
            DB::table('journal_entries')->where('id', $entryId)->update([
                'status' => 'posted', 'number' => (int) DB::table('journal_entries')->where('accounting_period_id', $periodId)->max('number') + 1,
                'posted_by' => $userId, 'posted_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Fiscal columns of a single-line demo document whose price includes the 12% VAT.
     *
     * @return array{fiscal_document_type: string, fiscal_series: string, fiscal_number: string, taxable_goods_cents: int, taxable_services_cents: int, vat_cents: int, vat_rate_basis_points: int}
     */
    private function fiscalBreakdown(int $total, string $treatment, string $series, string $number): array
    {
        $vat = $this->includedVat($total);

        return [
            'fiscal_document_type' => 'FACT',
            'fiscal_series' => $series,
            'fiscal_number' => $number,
            'taxable_goods_cents' => $treatment === 'goods' ? $total - $vat : 0,
            'taxable_services_cents' => $treatment === 'services' ? $total - $vat : 0,
            'vat_cents' => $vat,
            'vat_rate_basis_points' => 1200,
        ];
    }

    private function includedVat(int $total): int
    {
        return app(FiscalConfigurationService::class)->includedTaxCents($total, 1200);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $table, array $identity, array $values): int
    {
        $row = DB::table($table)->where($identity)->first();
        if ($row) {
            unset($values['public_id'], $values['created_at']);
            if (DB::getSchemaBuilder()->hasColumn($table, 'updated_at')) {
                $values['updated_at'] = now();
            }
            DB::table($table)->where('id', $row->id)->update($values);

            return (int) $row->id;
        }
        if (DB::getSchemaBuilder()->hasColumn($table, 'created_at')) {
            $values['created_at'] = now();
            $values['updated_at'] = now();
        }

        return (int) DB::table($table)->insertGetId([...$identity, ...$values]);
    }
}
