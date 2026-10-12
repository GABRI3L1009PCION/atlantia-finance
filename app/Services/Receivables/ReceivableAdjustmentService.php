<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Authorized reductions of a receivable: adjustments, exchange differences and write-offs. A request never changes the
 * balance; only its approval by someone other than the requester does, posting the counter account (an expense of
 * the company) against the receivable control account. Write-offs need their own permission.
 */
class ReceivableAdjustmentService
{
    public const KINDS = ['adjustment' => 'Ajuste de cartera', 'exchange_difference' => 'Diferencial cambiario', 'write_off' => 'Castigo (incobrable)'];

    public const STATUS_LABELS = ['pending' => 'Pendiente de aprobación', 'approved' => 'Aprobado', 'rejected' => 'Rechazado'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private ReceivableService $receivables,
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * Accounts that can absorb a reduction: active expense accounts of the company that accept movements.
     *
     * @return list<array{id: int, label: string}>
     */
    public function counterAccounts(int $companyId): array
    {
        return array_values(DB::table('ledger_accounts')->where('company_id', $companyId)->where('type', 'expense')->where('is_active', true)->where('accepts_movements', true)->where('is_group', false)
            ->orderBy('code')->get(['id', 'code', 'name'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'label' => $row->code.' '.$row->name])->all());
    }

    public function request(Organization $organization, User $actor, int $invoiceId, string $kind, int $amountCents, int $ledgerAccountId, string $reason): int
    {
        return DB::transaction(function () use ($organization, $actor, $invoiceId, $kind, $amountCents, $ledgerAccountId, $reason): int {
            if (! array_key_exists($kind, self::KINDS)) {
                throw ValidationException::withMessages(['kind' => 'Tipo de ajuste inválido.']);
            }
            abort_unless($this->authorization->can($organization, $actor, $kind === 'write_off' ? 'receivables.write_off' : 'receivables.adjustment.create'), 403);
            $document = $this->receivables->find($invoiceId);
            if ($document === null || $document['outstanding_cents'] <= 0) {
                throw ValidationException::withMessages(['amount' => 'Solo se ajustan documentos con saldo pendiente.']);
            }
            $pending = (int) DB::table('receivable_adjustments')->where('sales_invoice_id', $invoiceId)->where('status', 'pending')->sum('amount_cents');
            if ($amountCents <= 0 || $amountCents + $pending > $document['outstanding_cents']) {
                throw ValidationException::withMessages(['amount' => 'El ajuste no puede superar el saldo pendiente menos los ajustes en trámite.']);
            }
            if (! in_array($ledgerAccountId, array_column($this->counterAccounts($document['company_id']), 'id'), true)) {
                throw ValidationException::withMessages(['ledger_account_id' => 'Elige una cuenta de gasto activa de la empresa.']);
            }
            $office = (int) DB::table('companies')->where('id', $document['company_id'])->value('office_id');
            $id = DB::table('receivable_adjustments')->insertGetId([
                'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $document['company_id'], 'sales_invoice_id' => $invoiceId, 'sales_customer_id' => $document['customer_id'],
                'kind' => $kind, 'amount_cents' => $amountCents, 'ledger_account_id' => $ledgerAccountId, 'reason' => $reason, 'status' => 'pending',
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'receivables.adjustment.requested', 'receivable_adjustments', $id, ['kind' => $kind, 'amount_cents' => $amountCents, 'invoice' => $document['document']], $office);
            foreach ($this->approverIds($organization, (int) $document['company_id'], $actor->id) as $recipient) {
                $this->notifications->notify($organization->id, $recipient, $actor->id, 'receivables.adjustment', self::KINDS[$kind].' por aprobar', $document['document'].' · Q '.number_format($amountCents / 100, 2), 'receivable_adjustments', $id);
            }

            return $id;
        });
    }

    public function approve(Organization $organization, User $actor, int $adjustmentId, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $adjustmentId, $note): void {
            $row = $this->pending($organization, $actor, $adjustmentId);
            $this->accounting->lock((int) $row->company_id);
            $document = $this->receivables->find((int) $row->sales_invoice_id);
            if ($document === null || (int) $row->amount_cents > $document['outstanding_cents']) {
                throw ValidationException::withMessages(['adjustment' => 'El saldo del documento cambió y ya no alcanza para este ajuste.']);
            }
            $mapping = $this->automation->ensureMapping((int) $row->company_id);
            if ($mapping === null) {
                throw ValidationException::withMessages(['adjustment' => 'Configura el mapeo contable de la empresa.']);
            }
            $date = $this->receivables->today((int) $row->company_id);
            $closed = DB::table('accounting_periods')->where('company_id', $row->company_id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->whereNotNull('closed_at')->exists();
            if ($closed) {
                throw ValidationException::withMessages(['adjustment' => 'El período contable actual está cerrado.']);
            }
            $amount = number_format((int) $row->amount_cents / 100, 2, '.', '');
            $entry = $this->automation->draft($organization, $actor, (int) $row->company_id, 'receivable_adjustment', $adjustmentId, $date, self::KINDS[$row->kind].' · '.$document['document'].' · '.$document['customer'], 'AJ-CXC-'.$adjustmentId, [
                ['account_id' => (int) $row->ledger_account_id, 'debit' => $amount, 'credit' => '0', 'description' => $row->reason],
                ['account_id' => (int) $mapping->receivable_account_id, 'debit' => '0', 'credit' => $amount, 'third_party_type' => 'customer', 'third_party_id' => $row->sales_customer_id],
            ], 'receivable_adjustment.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['adjustment' => 'Abre un período contable para la fecha actual.']);
            }
            DB::table('receivable_adjustments')->where('id', $adjustmentId)->update(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'journal_entry_id' => $entry, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, $row->kind === 'write_off' ? 'receivables.write_off' : 'receivables.adjustment.approved', 'receivable_adjustments', $adjustmentId, ['amount_cents' => (int) $row->amount_cents], (int) $row->office_id);
            $this->notifications->notify($organization->id, (int) $row->requested_by, $actor->id, 'receivables.adjustment', self::KINDS[$row->kind].' aprobado', $document['document'], 'receivable_adjustments', $adjustmentId);
        });
    }

    public function reject(Organization $organization, User $actor, int $adjustmentId, string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $adjustmentId, $note): void {
            $row = $this->pending($organization, $actor, $adjustmentId);
            DB::table('receivable_adjustments')->where('id', $adjustmentId)->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'receivables.adjustment.rejected', 'receivable_adjustments', $adjustmentId, ['note' => $note], (int) $row->office_id);
            $this->notifications->notify($organization->id, (int) $row->requested_by, $actor->id, 'receivables.adjustment', self::KINDS[$row->kind].' rechazado', $note, 'receivable_adjustments', $adjustmentId);
        });
    }

    /** Whether the user may approve adjustments of the company (permission, scope and segregation). */
    public function canApprove(Organization $organization, User $user, stdClass $row): bool
    {
        if ((int) $row->requested_by === $user->id || ! $this->authorization->can($organization, $user, 'receivables.adjustment.approve')) {
            return false;
        }

        return in_array($user->id, $this->approverIds($organization, (int) $row->company_id, null), true);
    }

    /**
     * @param  list<int>|null  $companyIds  null: every company the user may approve for
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(Organization $organization, ?array $companyIds, array $filters = []): array
    {
        return array_values(DB::table('receivable_adjustments as adjustment')->join('sales_invoices as invoice', 'invoice.id', '=', 'adjustment.sales_invoice_id')->join('companies', 'companies.id', '=', 'adjustment.company_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'adjustment.ledger_account_id')->join('users as requester', 'requester.id', '=', 'adjustment.requested_by')->leftJoin('users as reviewer', 'reviewer.id', '=', 'adjustment.reviewed_by')
            ->where('adjustment.organization_id', $organization->id)
            ->when($companyIds !== null, fn ($query) => $query->whereIn('adjustment.company_id', $companyIds === [] ? [-1] : $companyIds))
            ->when(! empty($filters['invoice_id']), fn ($query) => $query->where('adjustment.sales_invoice_id', (int) $filters['invoice_id']))
            ->when(($filters['status'] ?? '') !== '', fn ($query) => $query->where('adjustment.status', (string) $filters['status']))
            ->orderByRaw("CASE adjustment.status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('adjustment.id')->limit(300)
            ->get(['adjustment.*', 'invoice.number', 'invoice.customer_name', 'companies.name as company_name', 'account.code', 'account.name as account_name', 'requester.name as requester', 'reviewer.name as reviewer'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name, 'invoice_id' => (int) $row->sales_invoice_id,
                'document' => 'Factura #'.$row->number, 'customer' => (string) $row->customer_name, 'kind' => (string) $row->kind, 'kind_label' => self::KINDS[$row->kind] ?? $row->kind,
                'amount_cents' => (int) $row->amount_cents, 'account' => $row->code.' '.$row->account_name, 'reason' => (string) $row->reason, 'status' => (string) $row->status,
                'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'requested_by' => (int) $row->requested_by, 'requester' => (string) $row->requester,
                'reviewer' => $row->reviewer, 'review_note' => $row->review_note, 'reviewed_at' => $row->reviewed_at === null ? null : Carbon::parse($row->reviewed_at)->toIso8601String(),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])->all());
    }

    private function pending(Organization $organization, User $actor, int $adjustmentId): stdClass
    {
        $row = DB::table('receivable_adjustments')->where('receivable_adjustments.organization_id', $organization->id)->where('id', $adjustmentId)->lockForUpdate()->first();
        abort_if($row === null, 404);
        if ($row->status !== 'pending') {
            throw ValidationException::withMessages(['adjustment' => 'El ajuste ya fue revisado.']);
        }
        if ((int) $row->requested_by === $actor->id) {
            throw ValidationException::withMessages(['adjustment' => 'Quien solicita el ajuste no puede aprobarlo ni rechazarlo.']);
        }
        abort_unless($this->canApprove($organization, $actor, $row), 403);

        return $row;
    }

    /** @return list<int> */
    private function approverIds(Organization $organization, int $companyId, ?int $except): array
    {
        $owners = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->pluck('user_id');
        $assigned = DB::table('company_assignments')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')->pluck('user_id');
        $office = DB::table('office_user')->where('office_id', DB::table('companies')->where('id', $companyId)->value('office_id'))->where('status', 'active')->pluck('user_id');

        return array_values(array_filter(array_unique(array_map('intval', [...$owners->all(), ...$assigned->all(), ...$office->all()])), function (int $userId) use ($organization, $except): bool {
            $user = User::find($userId);

            return $userId !== $except && $user !== null && $this->authorization->can($organization, $user, 'receivables.adjustment.approve');
        }));
    }
}
