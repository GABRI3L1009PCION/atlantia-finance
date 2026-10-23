<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Platform\UsageMeterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence requests. The auditor requests, the responsible person submits a file or links a document that already
 * exists in Atlantia, and the auditor reviews it. Accepting freezes the reviewed version (hash and snapshot), so a later
 * change of the source is visible as "version audited vs current". The original document is never modified.
 */
class AuditEvidenceService
{
    public const STATUS_LABELS = [
        'requested' => 'Solicitada', 'submitted' => 'Recibida', 'under_review' => 'En revisión', 'accepted' => 'Aceptada', 'rejected' => 'Rechazada',
        'more_information_required' => 'Más información', 'cancelled' => 'Cancelada',
    ];

    public const OPEN = ['requested', 'submitted', 'under_review', 'more_information_required'];

    /** Documents of Atlantia that may be linked as evidence: type => [label, table, company column path]. */
    public const SOURCES = [
        'purchase_documents' => 'Compra', 'sales_invoices' => 'Venta', 'journal_entries' => 'Asiento contable', 'payment_instructions' => 'Pago de Tesorería',
        'bank_transactions' => 'Movimiento bancario', 'payroll_periods' => 'Nómina', 'fel_documents' => 'Documento FEL', 'bank_reconciliations' => 'Conciliación bancaria',
    ];

    public function __construct(private AccountingService $accounting, private AuditEngagementService $engagements, private AuditAccessService $access, private AuditTaskService $tasks) {}

    /**
     * @param  array{title: string, description: string, requested_from_user_id: int, due_date: string, audit_procedure_id?: int|null, audit_finding_id?: int|null}  $data
     */
    public function request(Organization $organization, User $actor, int $engagementId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $data): int {
            $engagement = $this->engagements->lock($organization, $actor, $engagementId, 'audit.evidence.request');
            $this->engagements->assertOpen($engagement);
            foreach (['audit_procedure_id' => 'audit_procedures', 'audit_finding_id' => 'audit_findings'] as $key => $table) {
                if (($data[$key] ?? null) !== null) {
                    abort_unless(DB::table($table)->where('audit_engagement_id', $engagementId)->where('id', $data[$key])->exists(), 404);
                }
            }
            $this->assertCompanyMember($organization, (int) $data['requested_from_user_id'], (int) $engagement->company_id);
            $code = sprintf('EV-%04d', DB::table('audit_evidence_requests')->where('audit_evidence_requests.organization_id', $organization->id)->count() + 1);
            $id = DB::table('audit_evidence_requests')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId,
                'audit_procedure_id' => $data['audit_procedure_id'] ?? null, 'audit_finding_id' => $data['audit_finding_id'] ?? null, 'code' => $code, 'title' => $data['title'], 'description' => $data['description'],
                'requested_from_user_id' => $data['requested_from_user_id'], 'requested_by' => $actor->id, 'requested_at' => now(), 'due_date' => $data['due_date'], 'status' => 'requested', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (($data['audit_procedure_id'] ?? null) !== null) {
                DB::table('audit_procedures')->where('id', $data['audit_procedure_id'])->where('status', 'in_progress')->update(['status' => 'waiting_evidence', 'updated_at' => now()]);
            }
            $this->tasks->ensure($organization->id, (int) $engagement->company_id, (int) $data['requested_from_user_id'], 'audit_evidence:'.$id, 'submit_evidence', 'Entregar evidencia '.$code.' · '.$data['title'], $data['due_date'], 'audit_evidence_requests', $id, 'high');
            $this->tasks->notify($organization->id, (int) $data['requested_from_user_id'], $actor->id, 'Auditoría solicita evidencia: '.$code, $data['title'].' · vence '.Carbon::parse($data['due_date'])->format('d/m/Y'), 'audit_evidence_requests', $id);
            $this->accounting->audit($organization, $actor, 'audit.evidence.requested', 'audit_evidence_requests', $id, ['code' => $code, 'requested_from' => (int) $data['requested_from_user_id']], (int) $engagement->office_id);

            return $id;
        });
    }

    /** The responsible person submits a file or links an existing document of the company. */
    public function submit(Organization $organization, User $actor, int $requestId, string $note, ?UploadedFile $file, ?string $sourceType, ?int $sourceId): void
    {
        DB::transaction(function () use ($organization, $actor, $requestId, $note, $file, $sourceType, $sourceId): void {
            $request = $this->lock($organization, $requestId);
            abort_unless((int) $request->requested_from_user_id === $actor->id, 403);
            if (! in_array($request->status, ['requested', 'more_information_required', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'La solicitud ya fue respondida.']);
            }
            if ($file === null && $sourceType === null) {
                throw ValidationException::withMessages(['file' => 'Adjunta un archivo o selecciona un documento existente.']);
            }
            $values = ['status' => 'submitted', 'submission_note' => $note, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'evidence_version' => (int) $request->evidence_version + 1, 'updated_at' => now()];
            if ($sourceType !== null) {
                $row = $this->source((int) $request->company_id, $sourceType, (int) $sourceId);
                abort_if($row === null, 422, 'El documento no pertenece a la empresa auditada.');
                $values = [...$values, 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_label' => self::SOURCES[$sourceType].' #'.$sourceId];
            }
            if ($file !== null) {
                app(UsageMeterService::class)->assertCanAdd((int) $organization->id, 'storage_bytes', (int) $file->getSize(), 'file');
                $path = $file->storeAs('audit-evidence/'.$organization->id.'/'.$request->company_id, Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
                $values = [...$values, 'file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 250), 'file_size_bytes' => (int) $file->getSize(), 'file_hash' => hash_file('sha256', $file->getRealPath())];
            }
            DB::table('audit_evidence_requests')->where('id', $requestId)->update($values);
            $this->tasks->complete($organization->id, ['audit_evidence:'.$requestId]);
            $this->tasks->ensure($organization->id, (int) $request->company_id, (int) $request->requested_by, 'audit_evidence_review:'.$requestId, 'review_evidence', 'Revisar evidencia '.$request->code.' · '.$request->title, now()->addDays(3)->toDateString(), 'audit_evidence_requests', $requestId);
            $this->tasks->notify($organization->id, (int) $request->requested_by, $actor->id, 'Evidencia recibida: '.$request->code, $request->title, 'audit_evidence_requests', $requestId);
            $this->accounting->audit($organization, $actor, 'audit.evidence.submitted', 'audit_evidence_requests', $requestId, ['version' => $values['evidence_version'], 'source' => $sourceType, 'file_hash' => $values['file_hash'] ?? null]);
        });
    }

    /** review | accept | reject | more_information | cancel (auditor). */
    public function review(Organization $organization, User $actor, int $requestId, string $action, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $requestId, $action, $note): void {
            $request = $this->lock($organization, $requestId);
            $engagement = $this->engagements->lock($organization, $actor, (int) $request->audit_engagement_id, $action === 'cancel' ? 'audit.evidence.request' : 'audit.evidence.review');
            $needsNote = in_array($action, ['reject', 'more_information', 'cancel'], true);
            if ($needsNote && mb_strlen(trim((string) $note)) < 5) {
                throw ValidationException::withMessages(['note' => 'Explica el motivo al responsable.']);
            }
            [$allowed, $target] = match ($action) {
                'review' => [['submitted'], 'under_review'],
                'accept' => [['submitted', 'under_review'], 'accepted'],
                'reject' => [['submitted', 'under_review'], 'rejected'],
                'more_information' => [['submitted', 'under_review'], 'more_information_required'],
                'cancel' => [['requested', 'more_information_required', 'rejected'], 'cancelled'],
                default => abort(404),
            };
            if (! in_array($request->status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'Acción no disponible para evidencia '.mb_strtolower(self::STATUS_LABELS[$request->status]).'.']);
            }
            $values = ['status' => $target, 'updated_at' => now()];
            if ($action !== 'review') {
                $values = [...$values, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note];
            }
            if ($action === 'accept') {
                $snapshot = $request->source_type === null ? null : $this->source((int) $request->company_id, (string) $request->source_type, (int) $request->source_id);
                $values = [...$values, 'accepted_snapshot' => $snapshot === null ? null : json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'accepted_hash' => $snapshot === null ? $request->file_hash : hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
            }
            DB::table('audit_evidence_requests')->where('id', $requestId)->update($values);
            $this->tasks->complete($organization->id, ['audit_evidence_review:'.$requestId]);
            if (in_array($action, ['reject', 'more_information'], true)) {
                $this->tasks->ensure($organization->id, (int) $request->company_id, (int) $request->requested_from_user_id, 'audit_evidence:'.$requestId, 'submit_evidence', 'Completar evidencia '.$request->code.' · '.$request->title, (string) $request->due_date, 'audit_evidence_requests', $requestId, 'high');
                $this->tasks->notify($organization->id, (int) $request->requested_from_user_id, $actor->id, 'Auditoría requiere más información: '.$request->code, (string) $note, 'audit_evidence_requests', $requestId);
            }
            if ($action === 'cancel') {
                $this->tasks->complete($organization->id, ['audit_evidence:'.$requestId]);
            }
            if (in_array($action, ['accept', 'cancel'], true) && $request->audit_procedure_id !== null
                && ! DB::table('audit_evidence_requests')->where('audit_procedure_id', $request->audit_procedure_id)->whereIn('status', self::OPEN)->exists()) {
                DB::table('audit_procedures')->where('id', $request->audit_procedure_id)->where('status', 'waiting_evidence')->update(['status' => 'in_progress', 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'audit.evidence.'.$action, 'audit_evidence_requests', $requestId, ['note' => $note, 'version' => (int) $request->evidence_version, 'hash' => $values['accepted_hash'] ?? null], (int) $engagement->office_id);
        });
    }

    /** Download of the submitted file; every download is an audit event. */
    public function download(Organization $organization, User $actor, int $requestId): StreamedResponse
    {
        $request = DB::table('audit_evidence_requests')->where('audit_evidence_requests.organization_id', $organization->id)->where('id', $requestId)->first() ?? abort(404);
        $allowed = (int) $request->requested_from_user_id === $actor->id
            || (in_array((int) $request->audit_engagement_id, $this->access->engagementIds($organization, $actor), true) && $this->access->can($organization, $actor, 'audit.evidence.view'));
        abort_unless($allowed && $request->file_path !== null && Storage::disk('local')->exists((string) $request->file_path), 404);
        $this->accounting->audit($organization, $actor, 'audit.evidence.downloaded', 'audit_evidence_requests', $requestId, ['file' => $request->file_name, 'hash' => $request->file_hash]);

        return Storage::disk('local')->download((string) $request->file_path, (string) $request->file_name);
    }

    /**
     * Version of the evidence: the accepted one against what exists now in the source.
     *
     * @return array{version: int, audited_hash: string|null, current_hash: string|null, changed: bool|null}
     */
    public function versionState(stdClass $request): array
    {
        if ($request->status !== 'accepted') {
            return ['version' => (int) $request->evidence_version, 'audited_hash' => null, 'current_hash' => null, 'changed' => null];
        }
        if ($request->source_type === null) {
            return ['version' => (int) $request->evidence_version, 'audited_hash' => $request->accepted_hash, 'current_hash' => $request->file_hash, 'changed' => $request->accepted_hash !== $request->file_hash];
        }
        $current = $this->source((int) $request->company_id, (string) $request->source_type, (int) $request->source_id);
        $hash = $current === null ? null : hash('sha256', json_encode($current, JSON_THROW_ON_ERROR));

        return ['version' => (int) $request->evidence_version, 'audited_hash' => $request->accepted_hash, 'current_hash' => $hash, 'changed' => $hash === null ? null : $hash !== $request->accepted_hash];
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        $version = $this->versionState($row);

        return [
            'id' => (int) $row->id, 'code' => (string) $row->code, 'engagement_id' => (int) $row->audit_engagement_id, 'engagement' => (string) ($row->engagement_code ?? ''), 'company' => (string) ($row->company_name ?? ''),
            'procedure_id' => $row->audit_procedure_id === null ? null : (int) $row->audit_procedure_id, 'finding_id' => $row->audit_finding_id === null ? null : (int) $row->audit_finding_id,
            'title' => (string) $row->title, 'description' => (string) $row->description, 'requested_from_id' => (int) $row->requested_from_user_id,
            'requested_from' => (string) DB::table('users')->where('id', $row->requested_from_user_id)->value('name'), 'requested_at' => Carbon::parse($row->requested_at)->toIso8601String(),
            'due_date' => (string) $row->due_date, 'overdue' => in_array($row->status, self::OPEN, true) && (string) $row->due_date < now()->toDateString(), 'status' => (string) $row->status,
            'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status, 'submission_note' => $row->submission_note, 'submitted_at' => $row->submitted_at === null ? null : Carbon::parse($row->submitted_at)->toIso8601String(),
            'source_label' => $row->source_label, 'source_href' => $this->sourceHref($row), 'file_name' => $row->file_name, 'download' => $row->file_path === null ? null : '/work/audit/evidence/'.$row->id.'/download',
            'review_note' => $row->review_note, 'version' => $version['version'], 'changed_since_audit' => $version['changed'], 'destination' => '/work/audit/evidence?request='.$row->id,
        ];
    }

    /** @return array<string, mixed>|null */
    public function source(int $companyId, string $type, int $id): ?array
    {
        abort_unless(isset(self::SOURCES[$type]), 422);
        $row = match ($type) {
            'journal_entries' => DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('accounting_periods.company_id', $companyId)->where('journal_entries.id', $id)
                ->first(['journal_entries.id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.status', 'journal_entries.updated_at']),
            'bank_transactions' => DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_accounts.company_id', $companyId)->where('bank_transactions.id', $id)
                ->first(['bank_transactions.id', 'bank_transactions.transaction_date', 'bank_transactions.reference', 'bank_transactions.amount_cents', 'bank_transactions.reconciliation_status', 'bank_transactions.updated_at']),
            'bank_reconciliations' => DB::table('bank_reconciliations')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_reconciliations.bank_account_id')->where('bank_accounts.company_id', $companyId)->where('bank_reconciliations.id', $id)
                ->first(['bank_reconciliations.id', 'bank_reconciliations.cutoff_date', 'bank_reconciliations.status', 'bank_reconciliations.updated_at']),
            default => DB::table($type)->where('company_id', $companyId)->where('id', $id)->first(),
        };

        return $row === null ? null : (array) $row;
    }

    private function sourceHref(stdClass $row): ?string
    {
        return match ($row->source_type) {
            null => null,
            'purchase_documents' => '/payables/documents/'.$row->source_id,
            'sales_invoices' => '/receivables/documents/'.$row->source_id,
            'payment_instructions' => '/treasury/payments/'.$row->source_id,
            'payroll_periods' => '/payroll/periods/'.$row->source_id,
            default => '/work/audit/traceability?type='.$row->source_type.'&id='.$row->source_id.'&company_id='.$row->company_id,
        };
    }

    private function lock(Organization $organization, int $requestId): stdClass
    {
        return DB::table('audit_evidence_requests')->where('audit_evidence_requests.organization_id', $organization->id)->where('id', $requestId)->lockForUpdate()->first() ?? abort(404);
    }

    private function assertCompanyMember(Organization $organization, int $userId, int $companyId): void
    {
        $member = DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $userId)->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')->exists()
            || DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->where('role', 'owner')->where('status', 'active')->exists()
            || DB::table('client_company_access')->where('client_company_access.organization_id', $organization->id)->where('user_id', $userId)->where('company_id', $companyId)->where('status', 'active')->exists();
        if (! $member) {
            throw ValidationException::withMessages(['requested_from_user_id' => 'El responsable debe estar asignado a la empresa auditada o ser contacto del cliente en el portal.']);
        }
    }
}
