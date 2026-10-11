<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Payables\PayableService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\Receivables\ReceivableService;
use App\Services\Receivables\ReceivablesLedgerReconciliationService;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Re-checks, right before delivery, whether a reminder still matters by reading the real state of its source.
 * A reminder about something already resolved is skipped instead of sent.
 */
class LuzmaReminderConditionService
{
    public const CONDITIONS = [
        'payables_ledger_difference' => 'CxP sigue sin coincidir con el Mayor',
        'receivables_ledger_difference' => 'CxC sigue sin coincidir con el Mayor',
        'bank_unreconciled' => 'Hay movimientos bancarios sin conciliar',
        'fiscal_pending' => 'Hay obligaciones fiscales pendientes',
        'source_open' => 'El origen sigue pendiente',
    ];

    /** Open statuses of a fiscal obligation, as the fiscal panel defines them. */
    public const FISCAL_OPEN = ['pending', 'in_progress', 'ready_for_review', 'changes_requested', 'approved'];

    /** @return array{applies: bool, detail: string} */
    public function evaluate(stdClass $reminder): array
    {
        $companyId = $reminder->company_id === null ? null : (int) $reminder->company_id;
        $payload = json_decode((string) ($reminder->condition_payload ?? '{}'), true) ?: [];

        return match ((string) $reminder->condition_type) {
            'payables_ledger_difference' => $this->ledger(app(PayablesLedgerReconciliationService::class)->compute((int) $companyId, app(PayableService::class)->today((int) $companyId)), 'CxP'),
            'receivables_ledger_difference' => $this->ledger(app(ReceivablesLedgerReconciliationService::class)->compute((int) $companyId, app(ReceivableService::class)->today((int) $companyId)), 'CxC'),
            'bank_unreconciled' => $this->count(DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')
                ->where('bank_accounts.company_id', $companyId)->where('bank_transactions.reconciliation_status', '!=', 'matched')->count(), 'movimiento(s) bancario(s) sin conciliar'),
            'fiscal_pending' => $this->count(DB::table('fiscal_obligations')->where('company_id', $companyId)->whereIn('status', self::FISCAL_OPEN)->where('due_on', '<=', now()->addDays(3)->toDateString())->count(), 'obligación(es) fiscal(es) por presentar'),
            'source_open' => $this->source((string) $reminder->source_type, (int) $reminder->source_id, $payload),
            default => ['applies' => true, 'detail' => ''],
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{applies: bool, detail: string}
     */
    private function ledger(array $result, string $area): array
    {
        return match ($result['status']) {
            'difference' => ['applies' => true, 'detail' => $area.' sigue con diferencia de Q '.number_format((int) $result['difference_cents'] / 100, 2).' contra el Mayor.'],
            'error' => ['applies' => true, 'detail' => 'No fue posible verificar '.$area.' contra el Mayor; revísalo.'],
            default => ['applies' => false, 'detail' => $area.' ya coincide con el Mayor.'],
        };
    }

    /** @return list<int> */
    private function ids(mixed $ids): array
    {
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /** @return array{applies: bool, detail: string} */
    private function count(int $count, string $label): array
    {
        return $count > 0 ? ['applies' => true, 'detail' => $count.' '.$label.'.'] : ['applies' => false, 'detail' => 'Ya no hay pendientes.'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{applies: bool, detail: string}
     */
    private function source(string $type, int $id, array $payload): array
    {
        return match ($type) {
            'work_tasks' => $this->count(DB::table('work_tasks')->where('id', $id)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(), 'tarea pendiente'),
            'audit_evidence_requests' => $this->count(DB::table('audit_evidence_requests')->where('id', $id)->whereIn('status', ['requested', 'more_information_required', 'rejected'])->count(), 'evidencia de auditoría pendiente'),
            'audit_findings' => $this->count(DB::table('audit_findings')->where('id', $id)->whereIn('status', ['open', 'response_requested', 'remediation_in_progress'])->count(), 'hallazgo pendiente de respuesta o corrección'),
            'client_requests' => $this->count(DB::table('client_requests')->where('id', $id)->whereIn('status', ['pending', 'in_progress', 'more_information_required'])->count(), 'solicitud pendiente'),
            'client_approval_requests' => $this->count(DB::table('client_approval_requests')->where('id', $id)->whereIn('status', ['pending', 'viewed'])->count(), 'autorización pendiente'),
            'fiscal_obligations' => $this->count(DB::table('fiscal_obligations')->where('id', $id)->whereIn('status', self::FISCAL_OPEN)->count(), 'obligación pendiente'),
            'purchase_documents' => $this->count(count(array_filter(app(PayableService::class)->documents($this->ids($payload['company_ids'] ?? []), ['document_ids' => (array) ($payload['document_ids'] ?? [])]) ?: [], fn (array $row): bool => ! $row['in_proposal'] && $row['committed_cents'] === 0)), 'obligación(es) aún sin propuesta ni pago'),
            'sales_invoices' => $this->count(count(array_filter(app(ReceivableService::class)->documents($this->ids($payload['company_ids'] ?? []), []), fn (array $row): bool => in_array($row['id'], (array) ($payload['invoice_ids'] ?? []), true))), 'factura(s) aún con saldo'),
            default => ['applies' => true, 'detail' => ''],
        };
    }
}
