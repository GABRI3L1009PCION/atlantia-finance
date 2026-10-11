<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhookJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Turns audited actions into webhook deliveries for the organization's active endpoints subscribed to the event.
 * Deliveries are written inside the same transaction as the action (a rolled-back action sends nothing) and sent
 * after commit by a queued job; the scheduler retries whatever is still pending. A failure here never breaks the
 * business action.
 */
class WebhookDispatcher
{
    /** @var array<int, array{at: float, endpoints: list<stdClass>}> */
    private array $endpoints = [];

    public function __construct(private ApiResources $resources) {}

    /** Called by the central audit trail for every audited action. */
    public function fromAudit(int $organizationId, string $action, string $table, int $id): void
    {
        try {
            if ($action === 'receipt.applied' && $table === 'treasury_receipts') {
                foreach (DB::table('sales_receipts')->where('treasury_receipt_id', $id)->pluck('id') as $receiptId) {
                    $this->dispatch($organizationId, 'payment.received', 'sales_receipts', (int) $receiptId);
                }

                return;
            }
            if ($action === 'payment.executed' && $table === 'payment_instructions') {
                foreach (DB::table('purchase_payments')->where('payment_instruction_id', $id)->pluck('id') as $paymentId) {
                    $this->dispatch($organizationId, 'payment.sent', 'purchase_payments', (int) $paymentId);
                }

                return;
            }
            $event = WebhookEvents::ACTIONS[$action] ?? null;
            if ($event !== null && WebhookEvents::EVENTS[$event]['table'] === $table) {
                $this->dispatch($organizationId, $event, $table, $id);
            }
        } catch (Throwable $exception) {
            Log::warning('Webhook dispatch failed', ['action' => $action, 'table' => $table, 'id' => $id, 'error' => $exception->getMessage()]);
        }
    }

    public function dispatch(int $organizationId, string $event, string $table, int $id): void
    {
        $endpoints = array_values(array_filter($this->activeEndpoints($organizationId), fn (stdClass $endpoint): bool => in_array($event, (array) json_decode((string) $endpoint->events, true), true)));
        if ($endpoints === []) {
            return;
        }
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            return;
        }
        $companyId = $this->companyId($table, $row);
        $company = $companyId === null ? null : DB::table('companies')->where('id', $companyId)->first();
        $eventId = (string) Str::uuid();
        $payload = ['id' => $eventId, 'type' => $event, 'created_at' => now()->toIso8601String(), 'api_version' => 'v1',
            'company' => $company === null ? null : $this->resources->company($company), 'data' => $this->resource($table, $row)];

        foreach ($endpoints as $endpoint) {
            $restricted = $endpoint->company_ids === null ? null : array_map('intval', (array) json_decode((string) $endpoint->company_ids, true));
            if ($restricted !== null && ($companyId === null || ! in_array($companyId, $restricted, true))) {
                continue;
            }
            $this->queue($organizationId, $endpoint, $companyId, $event, $eventId, $payload);
        }
    }

    /**
     * Records a delivery and sends it after the surrounding transaction commits.
     *
     * @param  array<string, mixed>  $payload
     */
    public function queue(int $organizationId, stdClass $endpoint, ?int $companyId, string $event, string $eventId, array $payload): int
    {
        $deliveryId = DB::table('webhook_deliveries')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'webhook_endpoint_id' => $endpoint->id,
            'company_id' => $companyId, 'event' => $event, 'event_id' => $eventId, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DeliverWebhookJob::dispatch($deliveryId)->afterCommit();

        return $deliveryId;
    }

    /** Forgets the cached endpoints of an organization (after they are created, edited or disabled). */
    public function forget(int $organizationId): void
    {
        unset($this->endpoints[$organizationId]);
    }

    /** @return list<stdClass> */
    private function activeEndpoints(int $organizationId): array
    {
        $cached = $this->endpoints[$organizationId] ?? null;
        if ($cached !== null && microtime(true) - $cached['at'] < 30) {
            return $cached['endpoints'];
        }
        $endpoints = array_values(DB::table('webhook_endpoints')->where('webhook_endpoints.organization_id', $organizationId)->where('status', 'active')->get()->all());
        $this->endpoints[$organizationId] = ['at' => microtime(true), 'endpoints' => $endpoints];

        return $endpoints;
    }

    private function companyId(string $table, stdClass $row): ?int
    {
        $companyId = match ($table) {
            'sales_receipts' => DB::table('sales_invoices')->where('id', $row->sales_invoice_id)->value('company_id'),
            'purchase_payments' => DB::table('purchase_documents')->where('id', $row->purchase_document_id)->value('company_id'),
            default => $row->company_id ?? null,
        };

        return $companyId === null ? null : (int) $companyId;
    }

    /** @return array<string, mixed> */
    private function resource(string $table, stdClass $row): array
    {
        return match ($table) {
            'sales_customers' => $this->resources->customer($row),
            'purchase_suppliers' => $this->resources->supplier($row),
            'sales_invoices' => $this->resources->salesInvoice($row, true),
            'purchase_documents' => $this->resources->purchaseDocument($row, true),
            'sales_receipts' => $this->resources->salesReceipt($row),
            'purchase_payments' => $this->resources->purchasePayment($row),
            'sales_credit_notes' => $this->resources->creditNote($row),
            'fel_documents' => $this->resources->felDocument($row),
            'accounting_periods' => $this->resources->accountingPeriod($row),
            'published_reports' => $this->resources->publishedReport($row),
            'client_documents' => $this->resources->clientDocument($row),
            default => ['id' => (int) $row->id],
        };
    }
}
