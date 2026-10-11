<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Promises to pay. A promise never lowers the balance: it is fulfilled by the receipts applied to the customer's
 * invoices (or to the promised invoices) from the day it was made. Once its date passes without enough payment it
 * becomes broken (or partially fulfilled) and raises a follow-up task and an incident.
 */
class PromiseToPayService
{
    public const STATUS_LABELS = ['pending' => 'Pendiente', 'partially_fulfilled' => 'Cumplida parcialmente', 'fulfilled' => 'Cumplida', 'broken' => 'Incumplida', 'cancelled' => 'Cancelada'];

    public function __construct(
        private AccountingService $accounting,
        private ReceivableService $receivables,
    ) {}

    /**
     * @param  array{customer_id: int, amount_cents: int, promised_date: string, invoice_ids: list<int>, notes: string|null, collection_activity_id?: int|null}  $data
     */
    public function create(Organization $organization, User $actor, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data): int {
            $customer = DB::table('sales_customers')->where('id', $data['customer_id'])->where('company_id', $companyId)->first(['id', 'name']);
            if ($customer === null) {
                throw ValidationException::withMessages(['customer_id' => 'El cliente no pertenece a la empresa.']);
            }
            if ($data['amount_cents'] <= 0) {
                throw ValidationException::withMessages(['promise_amount' => 'El monto prometido debe ser mayor que cero.']);
            }
            if ($data['promised_date'] < $this->receivables->today($companyId)) {
                throw ValidationException::withMessages(['promise_date' => 'La fecha prometida no puede ser pasada.']);
            }
            $open = collect($this->receivables->documents([$companyId], ['customer_id' => $customer->id]));
            if ($open->isEmpty()) {
                throw ValidationException::withMessages(['customer_id' => 'El cliente no tiene saldo pendiente.']);
            }
            $invoiceIds = array_values(array_unique(array_map('intval', $data['invoice_ids'])));
            if (array_diff($invoiceIds, $open->pluck('id')->all()) !== []) {
                throw ValidationException::withMessages(['invoice_ids' => 'Solo se prometen documentos abiertos del cliente.']);
            }
            $limit = $invoiceIds === [] ? (int) $open->sum('outstanding_cents') : (int) $open->whereIn('id', $invoiceIds)->sum('outstanding_cents');
            if ($data['amount_cents'] > $limit) {
                throw ValidationException::withMessages(['promise_amount' => 'El monto prometido supera el saldo pendiente (Q '.number_format($limit / 100, 2).').']);
            }
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $id = DB::table('promises_to_pay')->insertGetId([
                'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'sales_customer_id' => $customer->id,
                'collection_activity_id' => $data['collection_activity_id'] ?? null, 'sales_invoice_ids' => $invoiceIds === [] ? null : json_encode($invoiceIds),
                'amount_cents' => $data['amount_cents'], 'promised_date' => $data['promised_date'], 'status' => 'pending', 'notes' => $data['notes'],
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'promise.created', 'promises_to_pay', $id, ['customer' => $customer->name, 'amount_cents' => $data['amount_cents'], 'promised_date' => $data['promised_date']], $office);

            return $id;
        });
    }

    public function cancel(Organization $organization, User $actor, int $promiseId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $promiseId, $reason): void {
            $promise = DB::table('promises_to_pay')->where('promises_to_pay.organization_id', $organization->id)->where('id', $promiseId)->lockForUpdate()->first();
            abort_if($promise === null, 404);
            if (! in_array($promise->status, ['pending', 'partially_fulfilled'], true)) {
                throw ValidationException::withMessages(['promise' => 'Solo se cancelan promesas vigentes.']);
            }
            DB::table('promises_to_pay')->where('id', $promiseId)->update(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'promise.cancelled', 'promises_to_pay', $promiseId, ['reason' => $reason], (int) $promise->office_id);
        });
    }

    /**
     * Re-evaluates the active promises against the receipts really applied. Idempotent.
     *
     * @param  list<int>  $companyIds
     * @return array{fulfilled: int, broken: int}
     */
    public function evaluate(Organization $organization, array $companyIds): array
    {
        $counts = ['fulfilled' => 0, 'broken' => 0];
        $assignees = DB::table('company_assignments')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('assignment_role', 'accounts_receivable')->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->get(['company_id', 'user_id'])->unique('company_id')->keyBy('company_id');
        foreach (DB::table('promises_to_pay')->where('promises_to_pay.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('status', ['pending', 'partially_fulfilled'])->get() as $promise) {
            $fulfilled = $this->fulfilledAmount($promise);
            $past = $promise->promised_date < $this->receivables->today((int) $promise->company_id);
            $status = match (true) {
                $fulfilled >= (int) $promise->amount_cents => 'fulfilled',
                $past && $fulfilled === 0 => 'broken',
                $fulfilled > 0 => 'partially_fulfilled',
                default => 'pending',
            };
            if ($status === $promise->status && $fulfilled === (int) $promise->fulfilled_cents) {
                continue;
            }
            DB::table('promises_to_pay')->where('id', $promise->id)->update(['status' => $status, 'fulfilled_cents' => $fulfilled, 'fulfilled_at' => $status === 'fulfilled' ? now() : null, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'office_id' => $promise->office_id, 'user_id' => null, 'action' => 'promise.'.$status, 'auditable_type' => 'promises_to_pay', 'auditable_id' => $promise->id, 'new_values' => json_encode(['fulfilled_cents' => $fulfilled]), 'occurred_at' => now(), 'created_at' => now()]);
            if ($status === 'fulfilled') {
                $counts['fulfilled']++;
            }
            // A partial payment after the promised date is a broken promise too for follow-up purposes.
            if ($past && $status !== 'fulfilled') {
                $counts['broken']++;
                $customer = (string) DB::table('sales_customers')->where('id', $promise->sales_customer_id)->value('name');
                $assignee = ($assignees->get($promise->company_id)->user_id ?? $promise->created_by);
                $key = 'broken_promise:'.$promise->id;
                if (! DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->exists()) {
                    DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $promise->office_id, 'company_id' => $promise->company_id, 'type' => 'receivables', 'severity' => 'high', 'status' => 'open',
                        'title' => 'Promesa incumplida: '.$customer, 'description' => 'Prometió Q '.number_format((int) $promise->amount_cents / 100, 2).' para el '.Carbon::parse($promise->promised_date)->format('d/m/Y').'; aplicado Q '.number_format($fulfilled / 100, 2).'.',
                        'blocks_period' => false, 'responsible_id' => $assignee, 'source_type' => 'promises_to_pay', 'source_id' => $promise->id, 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($assignee !== null && ! DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', $key)->exists()) {
                    DB::table('work_tasks')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $promise->office_id, 'company_id' => $promise->company_id, 'module' => 'receivables',
                        'task_type' => 'broken_promise', 'title' => 'Contactar a '.$customer.' por promesa incumplida', 'assigned_to' => $assignee, 'priority' => 'high', 'status' => 'pending',
                        'due_at' => now()->addDay()->endOfDay(), 'source_type' => 'promises_to_pay', 'source_id' => $promise->id, 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        return $counts;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $companyIds, array $filters = [], int $limit = 300): array
    {
        $status = (string) ($filters['status'] ?? 'active');

        return array_values(DB::table('promises_to_pay as promise')->join('sales_customers as customer', 'customer.id', '=', 'promise.sales_customer_id')->join('companies', 'companies.id', '=', 'promise.company_id')
            ->join('users', 'users.id', '=', 'promise.created_by')->whereIn('promise.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when($status === 'active', fn ($query) => $query->whereIn('promise.status', ['pending', 'partially_fulfilled']))
            ->when(array_key_exists($status, self::STATUS_LABELS), fn ($query) => $query->where('promise.status', $status))
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('promise.sales_customer_id', (int) $filters['customer_id']))
            ->orderBy('promise.promised_date')->limit($limit)
            ->get(['promise.*', 'customer.name as customer_name', 'companies.name as company_name', 'users.name as creator'])
            ->map(function (stdClass $row): array {
                $ids = json_decode((string) $row->sales_invoice_ids, true);

                return [
                    'id' => (int) $row->id,
                    'company_id' => (int) $row->company_id,
                    'company' => (string) $row->company_name,
                    'customer_id' => (int) $row->sales_customer_id,
                    'customer' => (string) $row->customer_name,
                    'amount_cents' => (int) $row->amount_cents,
                    'promised_date' => (string) $row->promised_date,
                    'fulfilled_cents' => (int) $row->fulfilled_cents,
                    'status' => (string) $row->status,
                    'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status,
                    'documents' => is_array($ids) && $ids !== [] ? DB::table('sales_invoices')->whereIn('id', $ids)->orderBy('number')->pluck('number')->map(fn ($number): string => '#'.$number)->all() : [],
                    'notes' => $row->notes,
                    'created_by' => (string) $row->creator,
                    'destination' => '/receivables/customers/'.$row->sales_customer_id,
                ];
            })->all());
    }

    private function fulfilledAmount(stdClass $promise): int
    {
        $ids = json_decode((string) $promise->sales_invoice_ids, true);

        return (int) DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
            ->where('sales_invoices.company_id', $promise->company_id)->where('sales_invoices.sales_customer_id', $promise->sales_customer_id)
            ->when(is_array($ids) && $ids !== [], fn ($query) => $query->whereIn('sales_invoices.id', $ids))
            ->whereNull('sales_receipts.voided_at')->where('sales_receipts.created_at', '>=', $promise->created_at)
            ->sum('sales_receipts.amount_cents');
    }
}
