<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\AccountingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class AtlantiaProductionDemoSeeder extends Seeder
{
    private const ORGANIZATION_ID = 2;

    private const COMPANY_ID = 2;

    public function run(): void
    {
        DB::transaction(function (): void {
            $company = DB::table('companies')->where('id', self::COMPANY_ID)->first();
            if ($company === null) {
                throw new RuntimeException('No existe la empresa objetivo para los datos demo.');
            }

            $office = DB::table('offices')
                ->where('id', $company->office_id)
                ->where('organization_id', self::ORGANIZATION_ID)
                ->where('status', 'active')
                ->first();

            if ($office === null) {
                throw new RuntimeException('La empresa objetivo no pertenece a una oficina activa de la organización esperada.');
            }

            $userId = (int) DB::table('organization_user')
                ->where('organization_id', self::ORGANIZATION_ID)
                ->where('role', 'owner')
                ->where('status', 'active')
                ->value('user_id');

            if ($userId <= 0) {
                throw new RuntimeException('No se encontró un propietario activo para la organización.');
            }

            DB::table('company_user')->updateOrInsert(
                ['company_id' => self::COMPANY_ID, 'user_id' => $userId],
                ['permission' => 'editor'],
            );

            app(AccountingService::class)->installDefaultChart(self::COMPANY_ID);

            $periodId = $this->periods(self::COMPANY_ID);
            $this->mappings(self::COMPANY_ID);
            $this->sales(self::COMPANY_ID, $userId);
            $this->purchases(self::COMPANY_ID, $userId);
            $this->banking(self::COMPANY_ID, $userId);
            $this->journal(self::COMPANY_ID, $periodId, $userId);
            $this->operationalRecords(self::COMPANY_ID, $periodId, $userId);
        });

        $this->command->info('Datos demo cargados correctamente en Atlantia Systems.');
    }

    private function periods(int $companyId): int
    {
        $currentId = 0;
        $anchor = CarbonImmutable::now()->startOfMonth();

        foreach (range(0, 5) as $offset) {
            $start = $anchor->subMonths($offset);
            $id = $this->upsert('accounting_periods', [
                'company_id' => $companyId,
                'starts_on' => $start->toDateString(),
                'ends_on' => $start->endOfMonth()->toDateString(),
            ], [
                'name' => 'Período '.$start->format('Y-m'),
                'closed_at' => null,
                'closed_by' => null,
            ]);

            if ($offset === 0) {
                $currentId = $id;
            }
        }

        return $currentId;
    }

    private function mappings(int $companyId): void
    {
        $account = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code');

        foreach (['1101', '1103', '4101', '2102', '5201'] as $code) {
            if (! isset($account[$code])) {
                throw new RuntimeException("Falta la cuenta contable {$code} en la empresa demo.");
            }
        }

        DB::table('company_accounting_mappings')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'cash_account_id' => $account['1101'],
                'receivable_account_id' => $account['1103'],
                'revenue_account_id' => $account['4101'],
                'payable_account_id' => $account['2102'],
                'default_expense_account_id' => $account['5201'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function sales(int $companyId, int $userId): void
    {
        $rows = [
            ['Comercial Los Pinos, S.A.', '12345678', 'compras@lospinos.test', 1250000],
            ['Distribuidora del Caribe, S.A.', '54879621', 'contabilidad@caribe.test', 875000],
            ['Servicios Portuarios GT', 'CF', 'pagos@portuarios.test', 540000],
        ];

        foreach ($rows as $index => [$name, $taxId, $email, $total]) {
            $customerId = $this->upsert(
                'sales_customers',
                ['company_id' => $companyId, 'tax_id' => $taxId],
                ['name' => $name, 'email' => $email],
            );

            $invoiceId = $this->upsert('sales_invoices', [
                'company_id' => $companyId,
                'number' => 20260901 + $index,
            ], [
                'public_id' => (string) Str::uuid(),
                'sales_customer_id' => $customerId,
                'status' => 'issued',
                'issued_on' => now()->subDays(12 - $index * 3)->toDateString(),
                'due_on' => now()->addDays(18 + $index * 3)->toDateString(),
                'total_cents' => $total,
                'customer_name' => $name,
                'customer_tax_id' => $taxId,
                'created_by' => $userId,
                'issued_by' => $userId,
                'issued_at' => now()->subDays(12 - $index * 3),
            ]);

            DB::table('sales_invoice_lines')->updateOrInsert(
                ['sales_invoice_id' => $invoiceId, 'position' => 1],
                [
                    'description' => 'Servicios contables y financieros',
                    'quantity' => 1,
                    'unit_cents' => $total,
                ],
            );

            if ($index < 2) {
                $this->upsert('sales_receipts', [
                    'sales_invoice_id' => $invoiceId,
                    'reference' => 'REC-DEMO-'.($index + 1),
                ], [
                    'public_id' => (string) Str::uuid(),
                    'amount_cents' => intdiv($total, 2),
                    'received_on' => now()->subDays(3 - $index)->toDateString(),
                    'created_by' => $userId,
                ]);
            }
        }
    }

    private function purchases(int $companyId, int $userId): void
    {
        $expense = (int) DB::table('ledger_accounts')
            ->where('company_id', $companyId)
            ->where('code', '5201')
            ->value('id');

        if ($expense <= 0) {
            throw new RuntimeException('No se encontró la cuenta de gasto 5201.');
        }

        $rows = [
            ['Tecnología Maya, S.A.', '77889901', 680000],
            ['Papelería Universal', '44556677', 185000],
            ['Servicios Eléctricos del Norte', '99887766', 320000],
        ];

        foreach ($rows as $index => [$name, $taxId, $total]) {
            $supplierId = $this->upsert('purchase_suppliers', [
                'company_id' => $companyId,
                'tax_id' => $taxId,
            ], [
                'name' => $name,
                'email' => 'facturacion'.($index + 1).'@proveedor.test',
                'phone' => '+502 2222 100'.($index + 1),
            ]);

            $documentId = $this->upsert('purchase_documents', [
                'company_id' => $companyId,
                'number' => 20260901 + $index,
            ], [
                'public_id' => (string) Str::uuid(),
                'purchase_supplier_id' => $supplierId,
                'document_type' => $index === 0 ? 'purchase' : 'expense',
                'external_reference' => 'FAC-PROV-'.($index + 101),
                'status' => 'approved',
                'document_date' => now()->subDays(10 - $index * 2)->toDateString(),
                'due_on' => now()->addDays(20 + $index * 2)->toDateString(),
                'total_cents' => $total,
                'supplier_name' => $name,
                'supplier_tax_id' => $taxId,
                'created_by' => $userId,
                'approved_by' => $userId,
                'approved_at' => now()->subDays(9 - $index * 2),
            ]);

            DB::table('purchase_document_lines')->updateOrInsert(
                ['purchase_document_id' => $documentId, 'position' => 1],
                [
                    'ledger_account_id' => $expense,
                    'description' => $index === 0 ? 'Compra de equipo y tecnología' : ($index === 1 ? 'Papelería y útiles de oficina' : 'Servicios eléctricos y mantenimiento'),
                    'quantity' => 1,
                    'unit_cents' => $total,
                ],
            );

            if ($index === 1) {
                $this->upsert('purchase_payments', [
                    'purchase_document_id' => $documentId,
                    'reference' => 'PAG-DEMO-1',
                ], [
                    'public_id' => (string) Str::uuid(),
                    'amount_cents' => $total,
                    'paid_on' => now()->subDays(2)->toDateString(),
                    'created_by' => $userId,
                ]);
            }
        }
    }

    private function banking(int $companyId, int $userId): void
    {
        $ledgerId = (int) DB::table('ledger_accounts')
            ->where('company_id', $companyId)
            ->where('code', '1102')
            ->value('id');

        if ($ledgerId <= 0) {
            throw new RuntimeException('No se encontró la cuenta bancaria contable 1102.');
        }

        $accountId = $this->upsert('bank_accounts', [
            'company_id' => $companyId,
            'ledger_account_id' => $ledgerId,
        ], [
            'public_id' => (string) Str::uuid(),
            'bank_name' => 'Banco Industrial',
            'account_name' => 'Cuenta Monetaria Atlantia Systems',
            'account_type' => 'checking',
            'last_four' => '4852',
            'currency' => 'GTQ',
            'bank_balance_cents' => 4652500,
            'last_reconciled_on' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'active',
            'created_by' => $userId,
        ]);

        $rows = [
            ['TRX-DEMO-001', 'Cobro de cliente Comercial Los Pinos', 'inflow', 625000, 4845000, 'matched'],
            ['TRX-DEMO-002', 'Pago a Papelería Universal', 'outflow', 185000, 4660000, 'matched'],
            ['TRX-DEMO-003', 'Comisión por manejo de cuenta', 'outflow', 7500, 4652500, 'pending'],
        ];

        foreach ($rows as $index => [$reference, $description, $flow, $amount, $balance, $status]) {
            $this->upsert('bank_transactions', [
                'bank_account_id' => $accountId,
                'reference' => $reference,
            ], [
                'public_id' => (string) Str::uuid(),
                'matched_journal_entry_id' => null,
                'transaction_date' => now()->subDays(5 - $index)->toDateString(),
                'description' => $description,
                'flow' => $flow,
                'amount_cents' => $amount,
                'running_balance_cents' => $balance,
                'reconciliation_status' => $status,
                'created_by' => $userId,
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
            if (! isset($account[$debit], $account[$credit])) {
                throw new RuntimeException("Faltan cuentas contables para el asiento {$description}.");
            }

            $entry = DB::table('journal_entries')
                ->where('accounting_period_id', $periodId)
                ->where('description', $description)
                ->first();

            $entryId = $entry !== null
                ? (int) $entry->id
                : (int) DB::table('journal_entries')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'accounting_period_id' => $periodId,
                    'entry_date' => now()->toDateString(),
                    'description' => $description,
                    'kind' => 'standard',
                    'status' => 'posted',
                    'number' => (int) DB::table('journal_entries')->where('accounting_period_id', $periodId)->max('number') + 1,
                    'created_by' => $userId,
                    'posted_by' => $userId,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('journal_lines')->updateOrInsert(
                ['journal_entry_id' => $entryId, 'position' => 1],
                ['ledger_account_id' => $account[$debit], 'debit_cents' => $amount, 'credit_cents' => 0],
            );

            DB::table('journal_lines')->updateOrInsert(
                ['journal_entry_id' => $entryId, 'position' => 2],
                ['ledger_account_id' => $account[$credit], 'debit_cents' => 0, 'credit_cents' => $amount],
            );
        }
    }

    private function operationalRecords(int $companyId, int $periodId, int $userId): void
    {
        foreach ($this->catalog() as $module => $definition) {
            foreach ([0, 1] as $index) {
                $reference = sprintf(
                    '%s-DEMO-%03d',
                    strtoupper(substr(str_replace('-', '', $module), 0, 8)),
                    $index + 1,
                );

                $status = $definition['statuses'][$index];
                $recordKey = $this->uuid("record:{$companyId}:{$module}:{$reference}");

                $recordId = $this->upsert('operational_records', [
                    'company_id' => $companyId,
                    'module' => $module,
                    'idempotency_key' => $recordKey,
                ], [
                    'public_id' => (string) Str::uuid(),
                    'accounting_period_id' => $periodId,
                    'journal_entry_id' => null,
                    'record_type' => $definition['type'],
                    'reference' => $reference,
                    'title' => $definition['titles'][$index],
                    'status' => $status,
                    'effective_on' => today()->subDays($index * 2)->toDateString(),
                    'currency' => 'GTQ',
                    'amount_cents' => $definition['amounts'][$index],
                    'data' => json_encode([...$definition['data'], 'seed_variant' => $index + 1], JSON_THROW_ON_ERROR),
                    'updated_by' => $userId,
                    'created_by' => $userId,
                    'approved_by' => $this->approved($status) ? $userId : null,
                    'approved_at' => $this->approved($status) ? now() : null,
                    'closed_by' => $status === 'closed' ? $userId : null,
                    'closed_at' => $status === 'closed' ? now() : null,
                ]);

                $eventKey = $this->uuid("event:{$companyId}:{$module}:{$reference}");
                $this->upsert('operational_record_events', [
                    'operational_record_id' => $recordId,
                    'idempotency_key' => $eventKey,
                ], [
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'action' => 'seeded',
                    'from_status' => null,
                    'to_status' => $status,
                    'reason' => 'Datos iniciales del entorno de demostración.',
                    'before_values' => null,
                    'after_values' => json_encode([
                        'reference' => $reference,
                        'title' => $definition['titles'][$index],
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                ]);
            }
        }
    }

    /**
     * @return array<string, array{
     *     type: string,
     *     titles: list<string>,
     *     statuses: list<string>,
     *     amounts: list<int>,
     *     data: array<string, mixed>
     * }>
     */
    private function catalog(): array
    {
        return [
            'conciliacion' => $this->item('conciliacion', ['Conciliación Banco Industrial ••••4852', 'Conciliación del mes anterior'], ['in_progress', 'closed'], [35000, 0], ['bank_account' => 'Banco Industrial ••••4852', 'bank_balance' => '46525.00', 'book_balance' => '46175.00']),
            'reglas' => $this->item('regla', ['Comisiones Banco Industrial', 'Cobros de clientes'], ['active', 'testing'], [0, 0], ['condition' => 'Descripción contiene COMISION', 'action' => 'Gastos bancarios', 'priority' => 10]),
            'caja' => $this->item('caja', ['Caja General', 'Arqueo de caja chica'], ['open', 'closed'], [5235000, 1250000], ['cashbox' => 'Caja General', 'responsible' => 'Administrador', 'movement' => 'Apertura y arqueo']),
            'inventario' => $this->item('producto', ['Laptop Dell Inspiron 15', 'Resma de papel carta'], ['approved', 'draft'], [6600000, 408000], ['code' => 'PROD-001', 'category' => 'Tecnología', 'warehouse' => 'Bodega Central']),
            'kardex' => $this->item('movimiento', ['Entrada por compra de inventario', 'Salida por venta de inventario'], ['posted', 'review'], [3640000, 1456000], ['product' => 'PROD-001 - Laptop Dell Inspiron 15', 'warehouse' => 'Bodega Central', 'movement' => 'Entrada / salida']),
            'inventario-fisico' => $this->item('conteo', ['Conteo mensual Bodega Central', 'Conteo selectivo de tecnología'], ['counting', 'review'], [485000, 120000], ['warehouse' => 'Bodega Central', 'responsible' => 'Auditoría', 'method' => 'Conteo normal']),
            'activos-fijos' => $this->item('activo', ['Laptop Dell Latitude 5520', 'Impresora HP LaserJet'], ['active', 'maintenance'], [560000, 120000], ['code' => 'AF-000125', 'category' => 'Equipo de cómputo', 'location' => 'Oficina Principal']),
            'nomina' => $this->item('periodo', ['Nómina del período actual', 'Novedades de horas extra'], ['calculated', 'review'], [42580000, 240000], ['period' => now()->format('m/Y'), 'employees' => 4, 'deductions' => '52700.00']),
            'proyectos' => $this->item('proyecto', ['Implementación del sistema contable', 'Migración de datos financieros'], ['active', 'paused'], [12000000, 9500000], ['client' => 'Comercial Los Pinos, S.A.', 'responsible' => 'Administración', 'progress' => '68%']),
            'presupuestos' => $this->item('presupuesto', ['Presupuesto operativo anual', 'Partida de tecnología'], ['active', 'review'], [240000000, 24000000], ['period' => now()->year, 'cost_center' => 'Administración', 'execution' => '71.7%']),
            'normativa' => $this->item('norma', ['Código Tributario', 'Manual de cierre contable'], ['published', 'review'], [0, 0], ['code' => 'SAT-L-001', 'category' => 'Fiscal', 'version' => '5.0']),
            'documentos' => $this->item('documento', ['Factura de proveedor FAC-3287.pdf', 'Estado de cuenta Banco Industrial.pdf'], ['review', 'published'], [414400, 0], ['category' => 'Compras', 'related_to' => 'Factura / cuenta bancaria', 'owner' => 'Administración']),
            'cotizaciones' => $this->item('cotizacion', ['Soluciones tecnológicas para oficinas', 'Servicios contables mensuales'], ['sent', 'approved'], [3416000, 1250000], ['client' => 'Comercial Los Pinos, S.A.', 'seller' => 'Administración', 'valid_until' => today()->addDays(15)->toDateString()]),
            'portal-cliente' => $this->item('solicitud', ['Estados de cuenta bancarios del mes', 'Contratos de proveedores nuevos'], ['in_review', 'pending'], [0, 0], ['client' => 'Comercial Los Pinos, S.A.', 'priority' => 'Alta', 'responsible' => 'Administración']),
            'notas-credito' => $this->item('nota_credito', ['Devolución parcial de factura', 'Bonificación comercial'], ['review', 'issued'], [250000, 120000], ['invoice' => '20260901', 'client' => 'Comercial Los Pinos, S.A.', 'reason' => 'Ajuste comercial']),
            'ordenes-compra' => $this->item('orden', ['Renovación de equipo de cómputo', 'Papelería para oficina principal'], ['issued', 'received'], [4523000, 654000], ['supplier' => 'Tecnología Maya, S.A.', 'buyer' => 'Administración', 'cost_center' => 'Tecnología']),
            'libros-iva' => $this->item('libro', ['Libro de ventas del período', 'Libro de compras del período'], ['reconciled', 'review'], [12483250, 8630125], ['period' => now()->format('m/Y'), 'book' => 'Compras y ventas', 'documents' => 3]),
            'cierre-periodo' => $this->item('cierre', ['Checklist de cierre actual', 'Cierre del período anterior'], ['validation', 'closed'], [0, 0], ['period' => now()->format('m/Y'), 'responsible' => 'Administración', 'completion' => '85%']),
            'estados-cuenta' => $this->item('estado', ['Estado de cuenta Comercial Los Pinos', 'Estado de cuenta Tecnología Maya'], ['generated', 'sent'], [13543000, 680000], ['party' => 'Comercial Los Pinos, S.A.', 'period' => now()->format('m/Y'), 'balance' => '135430.00']),
            'recordatorios' => $this->item('recordatorio', ['Presentar declaración IVA', 'Conciliación bancaria mensual'], ['pending', 'snoozed'], [0, 0], ['due_at' => today()->addDays(5)->toDateString(), 'priority' => 'Alta', 'responsible' => 'Administración']),
            'auditoria' => $this->item('evento', ['Revisión de orden de compra', 'Revisión de reapertura de período'], ['open', 'reviewed'], [4523000, 0], ['module' => 'Compras', 'action' => 'Modificación', 'risk' => 'Alto']),
            'usuarios-roles' => $this->item('usuario', ['Propietario - Administración', 'Auditoría interna'], ['active', 'active'], [0, 0], ['role' => 'Administrador', 'access' => 'Editor', 'scope' => 'Empresa']),
            'configuracion' => $this->item('parametro', ['Correo transaccional Resend', 'Moneda y zona horaria'], ['active', 'active'], [0, 0], ['area' => 'Integraciones', 'value' => 'GTQ / America/Guatemala', 'responsible' => 'Administrador']),
            'reportes' => $this->item('reporte', ['Estado de resultados mensual', 'Auxiliar de cuentas'], ['completed', 'completed'], [0, 0], ['category' => 'Financiero', 'format' => 'PDF / XLSX', 'period' => now()->format('m/Y')]),
            'impuestos' => $this->item('declaracion', ['Declaración mensual de IVA', 'Retenciones ISR del período'], ['pending_review', 'approved'], [3853125, 1275000], ['tax' => 'IVA / ISR', 'period' => now()->format('m/Y'), 'due_on' => today()->addDays(10)->toDateString()]),
        ];
    }

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $statuses
     * @param  list<int>  $amounts
     * @param  array<string, mixed>  $data
     * @return array{type: string, titles: list<string>, statuses: list<string>, amounts: list<int>, data: array<string, mixed>}
     */
    private function item(string $type, array $titles, array $statuses, array $amounts, array $data): array
    {
        return compact('type', 'titles', 'statuses', 'amounts', 'data');
    }

    private function approved(string $status): bool
    {
        return in_array($status, [
            'active',
            'approved',
            'completed',
            'closed',
            'issued',
            'paid',
            'posted',
            'published',
            'reconciled',
            'received',
        ], true);
    }

    private function uuid(string $value): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.production-demo.'.$value)->toString();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $table, array $identity, array $values): int
    {
        $row = DB::table($table)->where($identity)->first();

        if ($row !== null) {
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
