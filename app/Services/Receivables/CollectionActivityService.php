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
 * Collection follow-ups. An activity is never edited or deleted: a correction is a new activity pointing to the one
 * it corrects. The newest activity with a next action is the customer's open follow-up (older ones are superseded)
 * and its next action becomes a task for the person who registered it.
 */
class CollectionActivityService
{
    public const CHANNELS = ['call' => 'Llamada', 'email' => 'Correo', 'whatsapp' => 'WhatsApp', 'client_portal' => 'Portal del cliente', 'meeting' => 'Reunión', 'internal_note' => 'Nota interna', 'other' => 'Otro'];

    public const RESULTS = ['no_response' => 'Sin respuesta', 'client_contacted' => 'Cliente contactado', 'promise_to_pay' => 'Promesa de pago', 'disputed' => 'Documento en disputa', 'payment_confirmed' => 'Pago confirmado', 'requested_statement' => 'Solicitó estado de cuenta', 'follow_up_required' => 'Requiere seguimiento', 'other' => 'Otro'];

    public function __construct(
        private AccountingService $accounting,
        private PromiseToPayService $promises,
    ) {}

    /**
     * @param  array{customer_id: int, invoice_id: int|null, channel: string, result: string, notes: string|null, next_action: string|null, next_action_at: string|null, corrects_id?: int|null, promise_amount_cents?: int|null, promise_date?: string|null}  $data
     */
    public function register(Organization $organization, User $actor, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data): int {
            $customer = DB::table('sales_customers')->where('id', $data['customer_id'])->where('company_id', $companyId)->first(['id', 'name']);
            if ($customer === null) {
                throw ValidationException::withMessages(['customer_id' => 'El cliente no pertenece a la empresa.']);
            }
            if ($data['invoice_id'] !== null && ! DB::table('sales_invoices')->where('id', $data['invoice_id'])->where('company_id', $companyId)->where('sales_customer_id', $customer->id)->exists()) {
                throw ValidationException::withMessages(['invoice_id' => 'El documento no es de este cliente.']);
            }
            if (! array_key_exists($data['channel'], self::CHANNELS) || ! array_key_exists($data['result'], self::RESULTS)) {
                throw ValidationException::withMessages(['channel' => 'Canal o resultado inválido.']);
            }
            if (($data['next_action'] === null) !== ($data['next_action_at'] === null)) {
                throw ValidationException::withMessages(['next_action_at' => 'Indica la próxima acción y su fecha juntas.']);
            }
            $corrected = null;
            if (! empty($data['corrects_id'])) {
                $corrected = DB::table('collection_activities')->where('id', $data['corrects_id'])->where('company_id', $companyId)->where('sales_customer_id', $customer->id)->first();
                if ($corrected === null) {
                    throw ValidationException::withMessages(['corrects_id' => 'La gestión a corregir no es de este cliente.']);
                }
            }
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');

            DB::table('collection_activities')->where('company_id', $companyId)->where('sales_customer_id', $customer->id)->where('status', 'open')
                ->update(['status' => 'superseded', 'updated_at' => now()]);
            $id = DB::table('collection_activities')->insertGetId([
                'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'sales_customer_id' => $customer->id, 'sales_invoice_id' => $data['invoice_id'],
                'channel' => $data['channel'], 'result' => $data['result'], 'notes' => $data['notes'], 'next_action' => $data['next_action'],
                'next_action_at' => $data['next_action_at'] === null ? null : Carbon::parse($data['next_action_at']),
                'status' => $data['next_action'] === null ? 'done' : 'open', 'corrects_id' => $corrected?->id,
                'performed_by' => $actor->id, 'performed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Older follow-up tasks of this customer are replaced by the new one.
            DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('source_type', 'collection_activities')->where('company_id', $companyId)
                ->whereIn('source_id', DB::table('collection_activities')->where('sales_customer_id', $customer->id)->where('id', '!=', $id)->select('id'))
                ->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            if ($data['next_action'] !== null) {
                DB::table('work_tasks')->insert([
                    'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'module' => 'receivables',
                    'task_type' => 'collection_followup', 'title' => $data['next_action'].' · '.$customer->name, 'assigned_to' => $actor->id, 'priority' => 'normal', 'status' => 'pending',
                    'due_at' => Carbon::parse((string) $data['next_action_at']), 'source_type' => 'collection_activities', 'source_id' => $id, 'automation_key' => 'collection_followup:'.$id,
                    'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->accounting->audit($organization, $actor, $corrected === null ? 'collection_activity.created' : 'collection_activity.corrected', 'collection_activities', $id,
                ['customer' => $customer->name, 'channel' => $data['channel'], 'result' => $data['result'], 'corrects' => $corrected?->id], $office);

            if ($data['result'] === 'promise_to_pay') {
                if (empty($data['promise_amount_cents']) || empty($data['promise_date'])) {
                    throw ValidationException::withMessages(['promise_amount' => 'Una promesa de pago necesita monto y fecha prometida.']);
                }
                $this->promises->create($organization, $actor, $companyId, [
                    'customer_id' => (int) $customer->id, 'amount_cents' => (int) $data['promise_amount_cents'], 'promised_date' => (string) $data['promise_date'],
                    'invoice_ids' => $data['invoice_id'] === null ? [] : [(int) $data['invoice_id']], 'notes' => $data['notes'], 'collection_activity_id' => $id,
                ]);
            }

            return $id;
        });
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $companyIds, array $filters = [], int $limit = 300): array
    {
        return array_values(DB::table('collection_activities as activity')->join('sales_customers as customer', 'customer.id', '=', 'activity.sales_customer_id')->join('companies', 'companies.id', '=', 'activity.company_id')
            ->join('users', 'users.id', '=', 'activity.performed_by')->leftJoin('sales_invoices as invoice', 'invoice.id', '=', 'activity.sales_invoice_id')
            ->whereIn('activity.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('activity.sales_customer_id', (int) $filters['customer_id']))
            ->when(($filters['status'] ?? '') === 'overdue', fn ($query) => $query->where('activity.status', 'open')->where('activity.next_action_at', '<', now()))
            ->when(($filters['status'] ?? '') === 'open', fn ($query) => $query->where('activity.status', 'open'))
            ->orderByDesc('activity.performed_at')->orderByDesc('activity.id')->limit($limit)
            ->get(['activity.*', 'customer.name as customer_name', 'companies.name as company_name', 'users.name as performer', 'invoice.number as invoice_number'])
            ->map(fn (stdClass $row): array => $this->row($row))->all());
    }

    /** @return array<string, mixed> */
    private function row(stdClass $row): array
    {
        $overdue = $row->status === 'open' && $row->next_action_at !== null && Carbon::parse($row->next_action_at)->isPast();

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'company' => (string) $row->company_name,
            'customer_id' => (int) $row->sales_customer_id,
            'customer' => (string) $row->customer_name,
            'document' => $row->invoice_number === null ? null : 'Factura #'.$row->invoice_number,
            'channel' => (string) $row->channel,
            'channel_label' => self::CHANNELS[$row->channel] ?? $row->channel,
            'result' => (string) $row->result,
            'result_label' => self::RESULTS[$row->result] ?? $row->result,
            'notes' => $row->notes,
            'next_action' => $row->next_action,
            'next_action_at' => $row->next_action_at === null ? null : Carbon::parse($row->next_action_at)->toIso8601String(),
            'status' => $overdue ? 'overdue' : (string) $row->status,
            'status_label' => $overdue ? 'Vencida' : match ($row->status) {
                'open' => 'Seguimiento',
                'superseded' => 'Reemplazada',
                default => 'Cerrada',
            },
            'corrects_id' => $row->corrects_id === null ? null : (int) $row->corrects_id,
            'performer' => (string) $row->performer,
            'performed_at' => Carbon::parse($row->performed_at)->toIso8601String(),
            'destination' => '/receivables/customers/'.$row->sales_customer_id,
        ];
    }
}
