<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OperationalRecord;
use App\Models\OperationalRecordEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AtlantiaOperationalSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction();

        $company = DB::table('companies')->where('tax_id', '97655988')->first();
        $user = DB::table('users')->where('email', 'demo@atlantia.test')->first();
        if (! $company || ! $user) {
            $this->command->warn('Ejecuta AtlantiaDemoSeeder antes de AtlantiaOperationalSeeder.');

            return;
        }
        $periodId = DB::table('accounting_periods')->where('company_id', $company->id)
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->value('id');

        foreach ($this->catalog() as $module => $definition) {
            foreach ([0, 1] as $index) {
                $reference = sprintf('%s-DEMO-%03d', strtoupper(substr(str_replace('-', '', $module), 0, 8)), $index + 1);
                $record = OperationalRecord::query()->firstOrNew([
                    'company_id' => $company->id, 'module' => $module, 'reference' => $reference,
                ]);
                $status = $definition['statuses'][$index];
                $record->fill([
                    'accounting_period_id' => $periodId,
                    'record_type' => $definition['type'],
                    'title' => $definition['titles'][$index],
                    'status' => $status,
                    'effective_on' => today()->subDays($index * 2),
                    'currency' => 'GTQ',
                    'amount_cents' => $definition['amounts'][$index],
                    'data' => [...$definition['data'], 'seed_variant' => $index + 1],
                    'idempotency_key' => $this->uuid("record:$module:$reference"),
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'approved_by' => $this->approved($status) ? $user->id : null,
                    'approved_at' => $this->approved($status) ? now() : null,
                    'closed_by' => $status === 'closed' ? $user->id : null,
                    'closed_at' => $status === 'closed' ? now() : null,
                ]);
                $record->save();

                OperationalRecordEvent::query()->updateOrCreate(
                    ['operational_record_id' => $record->id, 'idempotency_key' => $this->uuid("event:$module:$reference")],
                    [
                        'company_id' => $company->id, 'user_id' => $user->id, 'action' => 'seeded',
                        'from_status' => null, 'to_status' => $status,
                        'reason' => 'Datos iniciales del entorno de demostración.',
                        'after_values' => ['reference' => $reference, 'title' => $record->title],
                        'occurred_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array{
     *   type: string, titles: list<string>, statuses: list<string>,
     *   amounts: list<int>, data: array<string, mixed>
     * }>
     */
    private function catalog(): array
    {
        return [
            'conciliacion' => $this->item('conciliacion', ['Conciliación Banco Industrial ••••4852', 'Conciliación del mes anterior'], ['in_progress', 'closed'], [35000, 0], ['bank_account' => 'Banco Industrial ••••4852', 'bank_balance' => '48450.00', 'book_balance' => '48100.00']),
            'reglas' => $this->item('regla', ['Comisiones Banco Industrial', 'Cobros de clientes'], ['active', 'testing'], [0, 0], ['condition' => 'Descripción contiene COMISION', 'action' => 'Gastos bancarios', 'priority' => 10]),
            'caja' => $this->item('caja', ['Caja General', 'Arqueo de caja chica'], ['open', 'closed'], [5235000, 1250000], ['cashbox' => 'Caja General', 'responsible' => 'María Contreras', 'movement' => 'Apertura y arqueo']),
            'inventario' => $this->item('producto', ['Laptop Dell Inspiron 15', 'Resma de papel carta'], ['approved', 'draft'], [6600000, 408000], ['code' => 'PROD-001', 'category' => 'Tecnología', 'warehouse' => 'Bodega Central']),
            'kardex' => $this->item('movimiento', ['Entrada por compra de inventario', 'Salida por venta de inventario'], ['posted', 'review'], [3640000, 1456000], ['product' => 'PROD-001 - Laptop Dell Inspiron 15', 'warehouse' => 'Bodega Central', 'movement' => 'Entrada / salida']),
            'inventario-fisico' => $this->item('conteo', ['Conteo mensual Bodega Central', 'Conteo selectivo de tecnología'], ['counting', 'review'], [485000, 120000], ['warehouse' => 'Bodega Central', 'responsible' => 'Carlos Méndez', 'method' => 'Conteo normal']),
            'activos-fijos' => $this->item('activo', ['Laptop Dell Latitude 5520', 'Impresora HP LaserJet'], ['active', 'maintenance'], [560000, 120000], ['code' => 'AF-000125', 'category' => 'Equipo de cómputo', 'location' => 'Oficina Central']),
            'nomina' => $this->item('periodo', ['Nómina del período actual', 'Novedades de horas extra'], ['calculated', 'review'], [42580000, 240000], ['period' => now()->format('m/Y'), 'employees' => 4, 'deductions' => '52700.00']),
            'proyectos' => $this->item('proyecto', ['Implementación del sistema contable', 'Migración de datos financieros'], ['active', 'paused'], [12000000, 9500000], ['client' => 'Comercial Los Pinos, S.A.', 'responsible' => 'Ana López', 'progress' => '68%']),
            'presupuestos' => $this->item('presupuesto', ['Presupuesto operativo anual', 'Partida de tecnología'], ['active', 'review'], [240000000, 24000000], ['period' => now()->year, 'cost_center' => 'Administración', 'execution' => '71.7%']),
            'normativa' => $this->item('norma', ['Código Tributario', 'Manual de cierre contable'], ['published', 'review'], [0, 0], ['code' => 'SAT-L-001', 'category' => 'Fiscal', 'version' => '5.0']),
            'documentos' => $this->item('documento', ['Factura de proveedor FAC-3287.pdf', 'Estado de cuenta Banco Industrial.pdf'], ['review', 'published'], [414400, 0], ['category' => 'Compras', 'related_to' => 'Factura / cuenta bancaria', 'owner' => 'Ana López']),
            'cotizaciones' => $this->item('cotizacion', ['Soluciones tecnológicas para oficinas', 'Servicios contables mensuales'], ['sent', 'approved'], [3416000, 1250000], ['client' => 'Comercial Los Pinos, S.A.', 'seller' => 'Ana López', 'valid_until' => today()->addDays(15)->toDateString()]),
            'portal-cliente' => $this->item('solicitud', ['Estados de cuenta bancarios del mes', 'Contratos de proveedores nuevos'], ['in_review', 'pending'], [0, 0], ['client' => 'Comercial Los Pinos, S.A.', 'priority' => 'Alta', 'responsible' => 'Ana López']),
            'notas-credito' => $this->item('nota_credito', ['Devolución parcial de factura', 'Bonificación comercial'], ['review', 'issued'], [250000, 120000], ['invoice' => '20260901', 'client' => 'Comercial Los Pinos, S.A.', 'reason' => 'Ajuste comercial']),
            'ordenes-compra' => $this->item('orden', ['Renovación de equipo de cómputo', 'Papelería para oficina central'], ['issued', 'received'], [4523000, 654000], ['supplier' => 'Tecnología Maya, S.A.', 'buyer' => 'María Contreras', 'cost_center' => 'Tecnología']),
            'libros-iva' => $this->item('libro', ['Libro de ventas del período', 'Libro de compras del período'], ['reconciled', 'review'], [12483250, 8630125], ['period' => now()->format('m/Y'), 'book' => 'Compras y ventas', 'documents' => 3]),
            'cierre-periodo' => $this->item('cierre', ['Checklist de cierre actual', 'Cierre del período anterior'], ['validation', 'closed'], [0, 0], ['period' => now()->format('m/Y'), 'responsible' => 'María Contreras', 'completion' => '85%']),
            'estados-cuenta' => $this->item('estado', ['Estado de cuenta Comercial Los Pinos', 'Estado de cuenta Tecnología Maya'], ['generated', 'sent'], [13543000, 680000], ['party' => 'Comercial Los Pinos, S.A.', 'period' => now()->format('m/Y'), 'balance' => '135430.00']),
            'recordatorios' => $this->item('recordatorio', ['Presentar declaración IVA', 'Conciliación bancaria mensual'], ['pending', 'snoozed'], [0, 0], ['due_at' => today()->addDays(5)->toDateString(), 'priority' => 'Alta', 'responsible' => 'María Contreras']),
            'auditoria' => $this->item('evento', ['Revisión de orden de compra', 'Revisión de reapertura de período'], ['open', 'reviewed'], [4523000, 0], ['module' => 'Compras', 'action' => 'Modificación', 'risk' => 'Alto']),
            'usuarios-roles' => $this->item('usuario', ['Ana López - Contabilidad', 'Carlos Méndez - Auditoría'], ['active', 'active'], [0, 0], ['email' => 'contabilidad@atlantia.test', 'role' => 'Contador', 'access' => 'Editor']),
            'configuracion' => $this->item('parametro', ['Correo transaccional Resend', 'Moneda y zona horaria'], ['active', 'active'], [0, 0], ['area' => 'Integraciones', 'value' => 'GTQ / America/Guatemala', 'responsible' => 'Administrador Atlantia']),
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
        return in_array($status, ['active', 'approved', 'completed', 'closed', 'issued', 'paid', 'posted', 'published', 'reconciled', 'received'], true);
    }

    private function uuid(string $value): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_DNS, 'atlantia-finance.demo.'.$value)->toString();
    }
}
