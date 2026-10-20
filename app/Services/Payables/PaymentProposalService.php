<?php

declare(strict_types=1);

namespace App\Services\Payables;

use App\Events\Work\SourceChanged;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Treasury\TreasuryPaymentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Payment proposals: accounts payable decides what to pay, to whom, how much and for when; Treasury decides how and
 * executes. A proposal never lowers a payable. It is submitted, approved by someone other than its preparer and sent
 * to Treasury, which receives one payment per supplier (plus one per advance line). Its status then follows the real
 * payments: partially executed while some are pending, completed when all were executed.
 */
class PaymentProposalService
{
    public const STATUS_LABELS = [
        'draft' => 'Borrador', 'pending_approval' => 'Por aprobar', 'approved' => 'Aprobada', 'rejected' => 'Rechazada',
        'sent_to_treasury' => 'En Tesorería', 'partially_executed' => 'Ejecutada parcialmente', 'completed' => 'Completada', 'cancelled' => 'Cancelada',
    ];

    private const ACTIVE = ['draft', 'pending_approval', 'approved'];

    public function __construct(
        private AccountingService $accounting,
        private PayableService $payables,
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * @param  array{proposed_date: string, priority: string, notes: string|null, items: list<array{kind?: string, purchase_document_id?: int|null, supplier_id?: int|null, amount_cents: int, priority?: string|null, reason?: string|null}>}  $data
     */
    public function create(Organization $organization, User $actor, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data): int {
            $this->accounting->lock($companyId);
            if ($data['items'] === []) {
                throw ValidationException::withMessages(['items' => 'Selecciona al menos un documento o anticipo.']);
            }
            if ($data['proposed_date'] < $this->payables->today($companyId)) {
                throw ValidationException::withMessages(['proposed_date' => 'La fecha propuesta no puede ser pasada.']);
            }
            $lines = $this->validateItems($companyId, $data['items']);
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $next = (int) DB::table('payment_proposals')->where('company_id', $companyId)->count() + 1;
            $id = DB::table('payment_proposals')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId,
                'reference' => 'PP-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT), 'proposed_date' => $data['proposed_date'], 'priority' => $data['priority'],
                'status' => 'draft', 'total_cents' => array_sum(array_column($lines, 'amount_cents')), 'notes' => $data['notes'], 'prepared_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                DB::table('payment_proposal_items')->insert([...$line, 'organization_id' => $organization->id, 'company_id' => $companyId, 'payment_proposal_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'payment_proposal.created', 'payment_proposals', $id, ['total_cents' => array_sum(array_column($lines, 'amount_cents')), 'items' => count($lines)], $office);

            return $id;
        });
    }

    public function submit(Organization $organization, User $actor, int $proposalId): void
    {
        DB::transaction(function () use ($organization, $actor, $proposalId): void {
            $proposal = $this->find($organization, $proposalId);
            if (! in_array($proposal->status, ['draft', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se envían a aprobación propuestas en borrador o rechazadas.']);
            }
            // Balances may have changed since the proposal was prepared.
            $this->validateItems((int) $proposal->company_id, $this->itemsOf($proposalId), $proposalId);
            DB::table('payment_proposals')->where('id', $proposalId)->update(['status' => 'pending_approval', 'review_note' => null, 'updated_at' => now()]);
            event(new SourceChanged(0, null, null, 'payment_proposals', (int) $proposalId));
            $this->accounting->audit($organization, $actor, 'payment_proposal.submitted', 'payment_proposals', $proposalId, [], (int) $proposal->office_id);
            foreach ($this->approverIds($organization, (int) $proposal->company_id, $actor->id) as $recipient) {
                $this->notifications->notify($organization->id, $recipient, $actor->id, 'payment_proposal', 'Propuesta de pago por aprobar', $proposal->reference.' · Q '.number_format((int) $proposal->total_cents / 100, 2), 'payment_proposals', $proposalId);
            }
        });
    }

    public function approve(Organization $organization, User $actor, int $proposalId, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $proposalId, $note): void {
            $proposal = $this->reviewable($organization, $actor, $proposalId);
            $this->validateItems((int) $proposal->company_id, $this->itemsOf($proposalId), $proposalId);
            DB::table('payment_proposals')->where('id', $proposalId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            event(new SourceChanged(0, null, null, 'payment_proposals', (int) $proposalId));
            $this->accounting->audit($organization, $actor, 'payment_proposal.approved', 'payment_proposals', $proposalId, ['note' => $note], (int) $proposal->office_id);
            $this->notifications->notify($organization->id, (int) $proposal->prepared_by, $actor->id, 'payment_proposal', 'Propuesta de pago aprobada', $proposal->reference, 'payment_proposals', $proposalId);
        });
    }

    public function reject(Organization $organization, User $actor, int $proposalId, string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $proposalId, $note): void {
            $proposal = $this->reviewable($organization, $actor, $proposalId);
            DB::table('payment_proposals')->where('id', $proposalId)->update(['status' => 'rejected', 'review_note' => $note, 'updated_at' => now()]);
            event(new SourceChanged(0, null, null, 'payment_proposals', (int) $proposalId));
            $this->accounting->audit($organization, $actor, 'payment_proposal.rejected', 'payment_proposals', $proposalId, ['note' => $note], (int) $proposal->office_id);
            $this->notifications->notify($organization->id, (int) $proposal->prepared_by, $actor->id, 'payment_proposal', 'Propuesta de pago rechazada', $proposal->reference.': '.$note, 'payment_proposals', $proposalId);
        });
    }

    public function cancel(Organization $organization, User $actor, int $proposalId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $proposalId, $reason): void {
            $proposal = $this->find($organization, $proposalId);
            if (! in_array($proposal->status, ['draft', 'pending_approval', 'approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Una propuesta enviada a Tesorería se cancela desde sus pagos.']);
            }
            DB::table('payment_proposals')->where('id', $proposalId)->update(['status' => 'cancelled', 'review_note' => $reason, 'updated_at' => now()]);
            event(new SourceChanged(0, null, null, 'payment_proposals', (int) $proposalId));
            $this->accounting->audit($organization, $actor, 'payment_proposal.cancelled', 'payment_proposals', $proposalId, ['reason' => $reason], (int) $proposal->office_id);
        });
    }

    /** Sends an approved proposal to Treasury: one payment per supplier, one per advance line, linked to the proposal. */
    public function sendToTreasury(Organization $organization, User $actor, int $proposalId): int
    {
        return DB::transaction(function () use ($organization, $actor, $proposalId): int {
            $proposal = $this->find($organization, $proposalId);
            if ($proposal->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Solo se envían a Tesorería propuestas aprobadas.']);
            }
            $items = DB::table('payment_proposal_items')->where('payment_proposal_id', $proposalId)->get();
            $payments = app(TreasuryPaymentService::class);
            $created = 0;
            // Invoices are grouped per supplier into one payment each.
            foreach ($items->where('kind', 'invoice')->groupBy('purchase_supplier_id') as $supplierItems) {
                $instruction = $payments->prepare($organization, $actor, (int) $proposal->company_id, [
                    'bank_account_id' => null, 'method' => 'transfer', 'concept' => 'Propuesta '.$proposal->reference, 'priority' => (string) $proposal->priority,
                    'scheduled_on' => (string) $proposal->proposed_date, 'payment_proposal_id' => $proposalId,
                    'items' => array_values($supplierItems->map(fn (stdClass $item): array => ['purchase_document_id' => (int) $item->purchase_document_id, 'amount_cents' => (int) $item->amount_cents])->all()),
                ]);
                DB::table('payment_proposal_items')->whereIn('id', $supplierItems->pluck('id'))->update(['payment_instruction_id' => $instruction, 'updated_at' => now()]);
                $created++;
            }
            foreach ($items->where('kind', 'advance') as $item) {
                $instruction = $payments->prepare($organization, $actor, (int) $proposal->company_id, [
                    'bank_account_id' => null, 'method' => 'transfer', 'concept' => 'Anticipo · propuesta '.$proposal->reference, 'priority' => (string) $proposal->priority,
                    'scheduled_on' => (string) $proposal->proposed_date, 'payment_proposal_id' => $proposalId, 'kind' => 'advance',
                    'supplier_id' => (int) $item->purchase_supplier_id, 'amount_cents' => (int) $item->amount_cents, 'items' => [],
                ]);
                DB::table('payment_proposal_items')->where('id', $item->id)->update(['payment_instruction_id' => $instruction, 'updated_at' => now()]);
                $created++;
            }
            DB::table('payment_proposals')->where('id', $proposalId)->update(['status' => 'sent_to_treasury', 'sent_at' => now(), 'updated_at' => now()]);
            event(new SourceChanged(0, null, null, 'payment_proposals', (int) $proposalId));
            $this->accounting->audit($organization, $actor, 'payment_proposal.sent_to_treasury', 'payment_proposals', $proposalId, ['payments' => $created], (int) $proposal->office_id);
            $treasury = DB::table('company_assignments')->where('company_id', $proposal->company_id)->where('assignment_role', 'treasury')->where('status', 'active')->whereNull('deleted_at')->pluck('user_id');
            foreach ($treasury as $recipient) {
                $this->notifications->notify($organization->id, (int) $recipient, $actor->id, 'payment_proposal', 'Propuesta de pago recibida', $proposal->reference.' · '.$created.' pago(s) por preparar y aprobar', 'payment_proposals', $proposalId);
            }

            return $created;
        });
    }

    /** Follows the payments of a proposal sent to Treasury (called whenever one of them changes). */
    public function refresh(int $proposalId): void
    {
        $proposal = DB::table('payment_proposals')->where('id', $proposalId)->first();
        if ($proposal === null || ! in_array($proposal->status, ['sent_to_treasury', 'partially_executed'], true)) {
            return;
        }
        $statuses = DB::table('payment_instructions')->where('payment_proposal_id', $proposalId)->pluck('status');
        if ($statuses->isEmpty()) {
            return;
        }
        $executed = $statuses->filter(fn ($status): bool => $status === 'executed')->count();
        $closed = $statuses->filter(fn ($status): bool => in_array($status, ['executed', 'rejected', 'cancelled', 'failed'], true))->count();
        $status = match (true) {
            $executed === $statuses->count() => 'completed',
            $closed === $statuses->count() && $executed === 0 => 'cancelled',
            $executed > 0 && $closed === $statuses->count() => 'completed',
            $executed > 0 => 'partially_executed',
            default => 'sent_to_treasury',
        };
        if ($status !== $proposal->status) {
            DB::table('payment_proposals')->where('id', $proposalId)->update(['status' => $status, 'updated_at' => now()]);
            event(new SourceChanged(0, null, null, 'payment_proposals', (int) $proposalId));
            $label = self::STATUS_LABELS[$status];
            $this->notifications->notify((int) $proposal->organization_id, (int) $proposal->prepared_by, null, 'payment_proposal', 'Propuesta '.$proposal->reference.': '.mb_strtolower($label),
                $executed.' de '.$statuses->count().' pago(s) ejecutados por Tesorería.', 'payment_proposals', $proposalId);
        }
    }

    public function canApprove(Organization $organization, User $user, stdClass $proposal): bool
    {
        return (int) $proposal->prepared_by !== $user->id && $this->authorization->can($organization, $user, 'payables.payment_proposals.approve')
            && in_array($user->id, $this->approverIds($organization, (int) $proposal->company_id, null), true);
    }

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(Organization $organization, ?array $companyIds, array $filters = []): array
    {
        $status = (string) ($filters['status'] ?? '');

        return array_values(DB::table('payment_proposals as proposal')->join('companies', 'companies.id', '=', 'proposal.company_id')->join('users as preparer', 'preparer.id', '=', 'proposal.prepared_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'proposal.approved_by')->where('proposal.organization_id', $organization->id)
            ->when($companyIds !== null, fn ($query) => $query->whereIn('proposal.company_id', $companyIds === [] ? [-1] : $companyIds))
            ->when($status === 'active', fn ($query) => $query->whereIn('proposal.status', ['draft', 'pending_approval', 'approved', 'sent_to_treasury', 'partially_executed']))
            ->when(array_key_exists($status, self::STATUS_LABELS), fn ($query) => $query->where('proposal.status', $status))
            ->orderByDesc('proposal.created_at')->limit(300)
            ->get(['proposal.*', 'companies.name as company_name', 'preparer.name as preparer_name', 'approver.name as approver_name'])
            ->map(fn (stdClass $row): array => $this->row($row))->all());
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        $items = DB::table('payment_proposal_items')->where('payment_proposal_id', $row->id)->get(['purchase_supplier_id', 'purchase_document_id', 'kind']);

        return [
            'id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) ($row->company_name ?? ''), 'reference' => (string) $row->reference,
            'proposed_date' => (string) $row->proposed_date, 'priority' => (string) $row->priority, 'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status,
            'total_cents' => (int) $row->total_cents, 'currency' => (string) $row->currency, 'suppliers_count' => $items->pluck('purchase_supplier_id')->unique()->count(),
            'documents_count' => $items->where('kind', 'invoice')->count(), 'advances_count' => $items->where('kind', 'advance')->count(), 'notes' => $row->notes,
            'prepared_by' => (int) $row->prepared_by, 'preparer' => $row->preparer_name ?? null, 'approver' => $row->approver_name ?? null,
            'approved_at' => $row->approved_at === null ? null : Carbon::parse($row->approved_at)->toIso8601String(), 'review_note' => $row->review_note,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(), 'destination' => '/payables/proposals/'.$row->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $proposalId): array
    {
        return array_values(DB::table('payment_proposal_items as item')->join('purchase_suppliers as supplier', 'supplier.id', '=', 'item.purchase_supplier_id')
            ->leftJoin('purchase_documents as document', 'document.id', '=', 'item.purchase_document_id')->leftJoin('payment_instructions as payment', 'payment.id', '=', 'item.payment_instruction_id')
            ->where('item.payment_proposal_id', $proposalId)->orderBy('supplier.name')->orderBy('item.id')
            ->get(['item.*', 'supplier.name as supplier_name', 'document.external_reference', 'document.number', 'document.due_on', 'payment.status as payment_status', 'payment.execution_reference'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'kind' => (string) $row->kind, 'supplier' => (string) $row->supplier_name, 'document_id' => $row->purchase_document_id === null ? null : (int) $row->purchase_document_id,
                'document' => $row->kind === 'advance' ? 'Anticipo' : ($row->external_reference ?: 'Compra #'.$row->number), 'due_on' => $row->due_on, 'amount_cents' => (int) $row->amount_cents,
                'priority' => (string) $row->priority, 'reason' => $row->reason, 'payment_id' => $row->payment_instruction_id === null ? null : (int) $row->payment_instruction_id,
                'payment_status' => $row->payment_status === null ? null : (TreasuryPaymentService::STATUS_LABELS[$row->payment_status] ?? $row->payment_status), 'execution_reference' => $row->execution_reference,
            ])->all());
    }

    /**
     * Validates proposal lines against the real balances: open document of the company, amount within what is not
     * already paid, committed to Treasury or proposed in another active proposal.
     *
     * @param  list<array{kind?: string, purchase_document_id?: int|null, supplier_id?: int|null, amount_cents: int, priority?: string|null, reason?: string|null}>  $items
     * @return list<array{kind: string, purchase_document_id: int|null, purchase_supplier_id: int, amount_cents: int, priority: string, reason: string|null}>
     */
    private function validateItems(int $companyId, array $items, ?int $exceptProposal = null): array
    {
        $lines = [];
        foreach ($items as $item) {
            $kind = $item['kind'] ?? 'invoice';
            if ((int) $item['amount_cents'] <= 0) {
                throw ValidationException::withMessages(['items' => 'Cada monto debe ser mayor que cero.']);
            }
            if ($kind === 'advance') {
                $supplier = DB::table('purchase_suppliers')->where('company_id', $companyId)->where('id', $item['supplier_id'] ?? 0)->value('id');
                if ($supplier === null) {
                    throw ValidationException::withMessages(['items' => 'El anticipo debe ser para un proveedor de la empresa.']);
                }
                $lines[] = ['kind' => 'advance', 'purchase_document_id' => null, 'purchase_supplier_id' => (int) $supplier, 'amount_cents' => (int) $item['amount_cents'], 'priority' => $item['priority'] ?? 'normal', 'reason' => $item['reason'] ?? null];

                continue;
            }
            $document = $this->payables->find((int) ($item['purchase_document_id'] ?? 0));
            if ($document === null || $document['company_id'] !== $companyId || $document['outstanding_cents'] <= 0) {
                throw ValidationException::withMessages(['items' => 'Solo se proponen documentos abiertos de la empresa.']);
            }
            $proposed = (int) DB::table('payment_proposal_items')->join('payment_proposals', 'payment_proposals.id', '=', 'payment_proposal_items.payment_proposal_id')
                ->where('payment_proposal_items.purchase_document_id', $document['id'])->whereIn('payment_proposals.status', self::ACTIVE)
                ->when($exceptProposal !== null, fn ($query) => $query->where('payment_proposals.id', '!=', $exceptProposal))->sum('payment_proposal_items.amount_cents');
            $available = $document['outstanding_cents'] - $document['committed_cents'] - $proposed;
            if ((int) $item['amount_cents'] > $available) {
                throw ValidationException::withMessages(['items' => $document['document'].' ya está en otra propuesta o en un pago de Tesorería; disponible para proponer: Q '.number_format(max(0, $available) / 100, 2).'.']);
            }
            $lines[] = ['kind' => 'invoice', 'purchase_document_id' => $document['id'], 'purchase_supplier_id' => $document['supplier_id'], 'amount_cents' => (int) $item['amount_cents'], 'priority' => $item['priority'] ?? 'normal', 'reason' => $item['reason'] ?? null];
        }
        $documentIds = array_filter(array_column($lines, 'purchase_document_id'));
        if (count($documentIds) !== count(array_unique($documentIds))) {
            throw ValidationException::withMessages(['items' => 'Un documento aparece dos veces en la propuesta.']);
        }

        return $lines;
    }

    /** @return list<array{kind: string, purchase_document_id: int|null, supplier_id: int, amount_cents: int}> */
    private function itemsOf(int $proposalId): array
    {
        return array_values(DB::table('payment_proposal_items')->where('payment_proposal_id', $proposalId)->get()
            ->map(fn (stdClass $item): array => ['kind' => (string) $item->kind, 'purchase_document_id' => $item->purchase_document_id === null ? null : (int) $item->purchase_document_id, 'supplier_id' => (int) $item->purchase_supplier_id, 'amount_cents' => (int) $item->amount_cents])->all());
    }

    private function reviewable(Organization $organization, User $actor, int $proposalId): stdClass
    {
        $proposal = $this->find($organization, $proposalId);
        if ($proposal->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'La propuesta no está pendiente de aprobación.']);
        }
        if ((int) $proposal->prepared_by === $actor->id) {
            throw ValidationException::withMessages(['status' => 'Quien prepara la propuesta no puede aprobarla ni rechazarla.']);
        }
        abort_unless($this->canApprove($organization, $actor, $proposal), 403);

        return $proposal;
    }

    private function find(Organization $organization, int $proposalId): stdClass
    {
        $proposal = DB::table('payment_proposals')->where('payment_proposals.organization_id', $organization->id)->where('id', $proposalId)->lockForUpdate()->first();
        abort_if($proposal === null, 404);

        return $proposal;
    }

    /** @return list<int> */
    private function approverIds(Organization $organization, int $companyId, ?int $except): array
    {
        $owners = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->pluck('user_id');
        $assigned = DB::table('company_assignments')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')->pluck('user_id');
        $office = DB::table('office_user')->where('office_id', DB::table('companies')->where('id', $companyId)->value('office_id'))->where('status', 'active')->pluck('user_id');

        return array_values(array_filter(array_unique(array_map('intval', [...$owners->all(), ...$assigned->all(), ...$office->all()])), function (int $userId) use ($organization, $except): bool {
            $user = User::find($userId);

            return $userId !== $except && $user !== null && $this->authorization->can($organization, $user, 'payables.payment_proposals.approve');
        }));
    }
}
