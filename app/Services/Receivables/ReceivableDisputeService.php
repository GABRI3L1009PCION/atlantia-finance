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
 * Disputes over an invoice. A dispute only flags the document: the balance stays until a credit note, a void, a
 * payment or an authorized adjustment changes it.
 */
class ReceivableDisputeService
{
    public const STATUS_LABELS = ['open' => 'Abierta', 'under_review' => 'En revisión', 'resolved' => 'Resuelta', 'rejected' => 'Rechazada', 'cancelled' => 'Cancelada'];

    public const REASONS = ['amount' => 'Monto incorrecto', 'goods_not_received' => 'Producto o servicio no recibido', 'quality' => 'Calidad o condiciones', 'duplicate' => 'Factura duplicada', 'already_paid' => 'Cliente indica que ya pagó', 'other' => 'Otro'];

    public function __construct(
        private AccountingService $accounting,
        private ReceivableService $receivables,
    ) {}

    public function open(Organization $organization, User $actor, int $companyId, int $invoiceId, string $reason, string $description): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $invoiceId, $reason, $description): int {
            $document = $this->receivables->find($invoiceId);
            if ($document === null || $document['company_id'] !== $companyId || $document['outstanding_cents'] <= 0) {
                throw ValidationException::withMessages(['invoice_id' => 'Solo se disputan documentos con saldo pendiente de la empresa.']);
            }
            if ($document['disputed']) {
                throw ValidationException::withMessages(['invoice_id' => 'El documento ya tiene una disputa activa.']);
            }
            if (! array_key_exists($reason, self::REASONS)) {
                throw ValidationException::withMessages(['reason' => 'Motivo inválido.']);
            }
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $id = DB::table('receivable_disputes')->insertGetId([
                'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'sales_customer_id' => $document['customer_id'], 'sales_invoice_id' => $invoiceId,
                'reason' => $reason, 'description' => $description, 'status' => 'open', 'responsible_id' => $actor->id, 'opened_by' => $actor->id, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'type' => 'receivables', 'severity' => 'medium', 'status' => 'open',
                'title' => 'Documento en disputa: '.$document['document'].' · '.$document['customer'], 'description' => self::REASONS[$reason].'. '.$description, 'blocks_period' => false, 'responsible_id' => $actor->id,
                'source_type' => 'receivable_disputes', 'source_id' => $id, 'automation_key' => 'dispute:'.$id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'dispute.opened', 'receivable_disputes', $id, ['invoice' => $document['document'], 'reason' => $reason], $office);

            return $id;
        });
    }

    public function transition(Organization $organization, User $actor, int $disputeId, string $status, ?string $resolution): void
    {
        DB::transaction(function () use ($organization, $actor, $disputeId, $status, $resolution): void {
            $dispute = DB::table('receivable_disputes')->where('receivable_disputes.organization_id', $organization->id)->where('id', $disputeId)->lockForUpdate()->first();
            abort_if($dispute === null, 404);
            if (! in_array($dispute->status, ['open', 'under_review'], true) || ! in_array($status, ['under_review', 'resolved', 'rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'La disputa ya está cerrada o el estado es inválido.']);
            }
            $closing = $status !== 'under_review';
            if ($closing && ($resolution === null || mb_strlen(trim($resolution)) < 5)) {
                throw ValidationException::withMessages(['resolution' => 'Explica cómo se resolvió la disputa (nota de crédito, corrección o pago).']);
            }
            DB::table('receivable_disputes')->where('id', $disputeId)->update(['status' => $status, 'resolution' => $closing ? $resolution : $dispute->resolution, 'closed_by' => $closing ? $actor->id : null, 'closed_at' => $closing ? now() : null, 'updated_at' => now()]);
            if ($closing) {
                DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', 'dispute:'.$disputeId)->whereIn('status', ['open', 'in_progress', 'blocked'])
                    ->update(['status' => 'resolved', 'resolution' => 'Disputa '.mb_strtolower(self::STATUS_LABELS[$status]).': '.$resolution, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'updated_at' => now()]);
                DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', 'review_dispute:'.$disputeId)->whereNotIn('status', ['completed', 'cancelled'])
                    ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'dispute.'.$status, 'receivable_disputes', $disputeId, ['resolution' => $resolution], (int) $dispute->office_id);
        });
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $companyIds, array $filters = []): array
    {
        $status = (string) ($filters['status'] ?? 'active');

        return array_values(DB::table('receivable_disputes as dispute')->join('sales_customers as customer', 'customer.id', '=', 'dispute.sales_customer_id')->join('sales_invoices as invoice', 'invoice.id', '=', 'dispute.sales_invoice_id')
            ->join('companies', 'companies.id', '=', 'dispute.company_id')->leftJoin('users as responsible', 'responsible.id', '=', 'dispute.responsible_id')
            ->whereIn('dispute.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when($status === 'active', fn ($query) => $query->whereIn('dispute.status', ['open', 'under_review']))
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('dispute.sales_customer_id', (int) $filters['customer_id']))
            ->orderByDesc('dispute.opened_at')->limit(300)
            ->get(['dispute.*', 'customer.name as customer_name', 'invoice.number', 'invoice.total_cents', 'companies.name as company_name', 'responsible.name as responsible_name'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'company' => (string) $row->company_name,
                'customer_id' => (int) $row->sales_customer_id,
                'customer' => (string) $row->customer_name,
                'invoice_id' => (int) $row->sales_invoice_id,
                'document' => 'Factura #'.$row->number,
                'outstanding_cents' => $this->receivables->find((int) $row->sales_invoice_id)['outstanding_cents'] ?? null,
                'reason' => self::REASONS[$row->reason] ?? $row->reason,
                'description' => (string) $row->description,
                'status' => (string) $row->status,
                'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status,
                'responsible' => $row->responsible_name,
                'opened_at' => Carbon::parse($row->opened_at)->toIso8601String(),
                'resolution' => $row->resolution,
                'destination' => '/receivables/documents/'.$row->sales_invoice_id,
            ])->all());
    }
}
