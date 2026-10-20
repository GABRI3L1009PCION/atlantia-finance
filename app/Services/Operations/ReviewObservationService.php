<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Review observations: each finding of a review is tracked on its own, tied to the record it concerns and to the person
 * who has to correct it, and flows OPEN → IN_PROGRESS → RESOLVED, or back to REOPENED when the fix is not accepted.
 */
class ReviewObservationService
{
    public const STATUSES = ['open' => 'Abierta', 'in_progress' => 'En corrección', 'ready_for_review' => 'Corregida, por validar', 'resolved' => 'Resuelta', 'reopened' => 'Reabierta'];

    public const SEVERITIES = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica'];

    public const RELATED_TYPES = [
        'journal_entries' => 'Póliza',
        'purchase_documents' => 'Compra',
        'sales_invoices' => 'Venta',
        'bank_accounts' => 'Cuenta bancaria',
        'vat_book_periods' => 'Libro IVA',
        'fiscal_obligations' => 'Obligación fiscal',
        'documents' => 'Documento',
        'general' => 'General',
    ];

    /** Not resolved yet: still to correct, or corrected and waiting for the reviewer. */
    public const ACTIVE = ['open', 'in_progress', 'reopened', 'ready_for_review'];

    /** Still in the hands of the responsible (to correct). */
    public const CORRECTABLE = ['open', 'in_progress', 'reopened'];

    public function __construct(
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * @param  array{company_id: int, accounting_period_id?: int|null, company_period_review_id?: int|null, related_type?: string|null, related_id?: int|null, related_label?: string|null, origin_type?: string|null, origin_id?: int|null, severity: string, message: string, assigned_to?: int|null, due_at?: string|null}  $data
     */
    public function create(Organization $organization, User $actor, array $data): int
    {
        $company = DB::table('companies')->where('id', $data['company_id'])->first(['id', 'office_id', 'name']);
        abort_if($company === null, 404);
        $assignee = $data['assigned_to'] ?? null;
        if ($assignee !== null && ! DB::table('company_assignments')->where('company_id', $company->id)->where('user_id', $assignee)->where('status', 'active')->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'El responsable debe estar asignado a la empresa.']);
        }

        $id = DB::table('review_observations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'office_id' => $company->office_id,
            'company_id' => $company->id,
            'accounting_period_id' => $data['accounting_period_id'] ?? null,
            'company_period_review_id' => $data['company_period_review_id'] ?? null,
            'related_type' => $data['related_type'] ?? 'general',
            'related_id' => $data['related_id'] ?? null,
            'related_label' => $data['related_label'] ?? null,
            'origin_type' => $data['origin_type'] ?? null,
            'origin_id' => $data['origin_id'] ?? null,
            'severity' => $data['severity'],
            'status' => 'open',
            'message' => $data['message'],
            'created_by' => $actor->id,
            'assigned_to' => $assignee,
            'due_at' => $data['due_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accounting->audit($organization, $actor, 'review_observation.created', 'review_observations', $id, ['company_id' => (int) $company->id, 'severity' => $data['severity'], 'message' => $data['message'], 'assigned_to' => $assignee], (int) $company->office_id);
        $this->notifications->notify($organization->id, $assignee, $actor->id, 'observation.assigned', 'Observación de revisión: '.$company->name, $data['message'], 'review_observations', $id);

        return $id;
    }

    /**
     * Moves an observation through its flow. The responsible corrects at the source and sends it back
     * (ready_for_review, with the correction made); only someone else (the reviewer) resolves or reopens it.
     */
    public function transition(Organization $organization, User $actor, int $observationId, string $status, ?string $note = null): void
    {
        DB::transaction(function () use ($organization, $actor, $observationId, $status, $note): void {
            $observation = DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->where('id', $observationId)->lockForUpdate()->first();
            abort_if($observation === null, 404);
            $isResponsible = $observation->assigned_to !== null && (int) $observation->assigned_to === $actor->id;
            $allowed = match ($status) {
                'in_progress' => in_array($observation->status, ['open', 'reopened'], true),
                'ready_for_review' => in_array($observation->status, self::CORRECTABLE, true),
                'resolved' => ! $isResponsible && in_array($observation->status, self::ACTIVE, true),
                'reopened' => ! $isResponsible && in_array($observation->status, ['resolved', 'ready_for_review'], true),
                default => false,
            };
            if (! $allowed) {
                $message = $isResponsible && in_array($status, ['resolved', 'reopened'], true)
                    ? 'Quien corrige no puede dar por resuelta la observación: envíala como corregida para que el revisor la valide.'
                    : 'La observación no permite pasar de '.(self::STATUSES[$observation->status] ?? $observation->status).' a '.(self::STATUSES[$status] ?? $status).'.';
                throw ValidationException::withMessages(['status' => $message]);
            }
            $needsNote = $status === 'ready_for_review' || $status === 'reopened' || ($status === 'resolved' && $observation->status !== 'ready_for_review');
            if ($needsNote && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => $status === 'reopened' ? 'Indica por qué se reabre la observación.' : 'Describe la corrección realizada.']);
            }
            if ($status === 'ready_for_review') {
                $this->assertSourceChanged($observation);
            }

            DB::table('review_observations')->where('id', $observationId)->update([
                'status' => $status,
                'resolution' => in_array($status, ['ready_for_review', 'resolved'], true) && trim((string) $note) !== '' ? $note : $observation->resolution,
                'resolved_by' => $status === 'resolved' ? $actor->id : ($status === 'reopened' ? null : $observation->resolved_by),
                'resolved_at' => $status === 'resolved' ? now() : ($status === 'reopened' ? null : $observation->resolved_at),
                'reopened_at' => $status === 'reopened' ? now() : $observation->reopened_at,
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'review_observation.'.$status, 'review_observations', $observationId, ['from' => $observation->status, 'to' => $status, 'note' => $note], $observation->office_id === null ? null : (int) $observation->office_id);

            $recipient = match ($status) {
                'ready_for_review' => $observation->created_by,
                'resolved', 'reopened' => $observation->assigned_to,
                default => null,
            };
            $this->notifications->notify($organization->id, $recipient === null ? null : (int) $recipient, $actor->id, 'observation.'.$status, 'Observación '.mb_strtolower(self::STATUSES[$status]), $note ?? (string) $observation->message, 'review_observations', $observationId);
        });
    }

    /**
     * When the observation points to a record, that record must have changed since the observation was raised (or
     * reopened): marking it corrected without touching the source is not allowed.
     */
    private function assertSourceChanged(stdClass $observation): void
    {
        $table = in_array($observation->related_type, ['journal_entries', 'purchase_documents', 'sales_invoices'], true) ? (string) $observation->related_type : null;
        if ($table === null || $observation->related_id === null) {
            return;
        }

        $updatedAt = DB::table($table)->where('id', $observation->related_id)->value('updated_at');
        $since = $observation->reopened_at ?? $observation->created_at;
        $reversed = $table === 'journal_entries' && DB::table('journal_entries')->where('reversal_of', $observation->related_id)->exists();
        if (! $reversed && ($updatedAt === null || Carbon::parse($updatedAt)->lte(Carbon::parse($since)))) {
            throw ValidationException::withMessages(['status' => 'El registro observado no ha cambiado desde la observación. Corrígelo en su origen antes de enviarlo.']);
        }
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(int $organizationId, array $companyIds, array $filters = [], int $limit = 300): array
    {
        return array_values(DB::table('review_observations as observation')
            ->join('companies', 'companies.id', '=', 'observation.company_id')
            ->leftJoin('accounting_periods as period', 'period.id', '=', 'observation.accounting_period_id')
            ->leftJoin('users as author', 'author.id', '=', 'observation.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'observation.assigned_to')
            ->where('observation.organization_id', $organizationId)
            ->whereIn('observation.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['company_id']), fn (Builder $query) => $query->where('observation.company_id', (int) $filters['company_id']))
            ->when(! empty($filters['status']), fn (Builder $query) => match ($filters['status']) {
                'active' => $query->whereIn('observation.status', self::ACTIVE),
                'correctable' => $query->whereIn('observation.status', self::CORRECTABLE),
                default => $query->where('observation.status', $filters['status']),
            })
            ->when(! empty($filters['assigned_to']), fn (Builder $query) => $query->where('observation.assigned_to', (int) $filters['assigned_to']))
            ->when(! empty($filters['related_type']), fn (Builder $query) => $query->where('observation.related_type', (string) $filters['related_type'])->where('observation.related_id', (int) ($filters['related_id'] ?? 0)))
            ->when(! empty($filters['review_id']), fn (Builder $query) => $query->where('observation.company_period_review_id', (int) $filters['review_id']))
            ->when(! empty($filters['period_id']), fn (Builder $query) => $query->where('observation.accounting_period_id', (int) $filters['period_id']))
            ->orderByRaw("CASE observation.status WHEN 'reopened' THEN 0 WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'ready_for_review' THEN 3 ELSE 4 END")
            ->orderByDesc('observation.id')
            ->limit($limit)
            ->get(['observation.*', 'companies.name as company_name', 'companies.public_id as company_public_id', 'period.name as period_name', 'author.name as author_name', 'assignee.name as assignee_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'company_name' => (string) $row->company_name,
                'period_name' => $row->period_name,
                'review_id' => $row->company_period_review_id === null ? null : (int) $row->company_period_review_id,
                'related_type' => (string) $row->related_type,
                'related_type_label' => self::RELATED_TYPES[$row->related_type] ?? (string) $row->related_type,
                'related_label' => $row->related_label,
                'related_href' => $this->relatedHref((string) $row->company_public_id, (string) $row->related_type, $row->related_id === null ? null : (int) $row->related_id),
                'severity' => (string) $row->severity,
                'severity_label' => self::SEVERITIES[$row->severity] ?? (string) $row->severity,
                'status' => (string) $row->status,
                'status_label' => self::STATUSES[$row->status] ?? (string) $row->status,
                'message' => (string) $row->message,
                'resolution' => $row->resolution,
                'author_name' => $row->author_name,
                'created_by' => $row->created_by === null ? null : (int) $row->created_by,
                'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to,
                'assignee_name' => $row->assignee_name,
                'due_at' => $row->due_at,
                'overdue' => $row->due_at !== null && in_array($row->status, self::ACTIVE, true) && Carbon::parse($row->due_at)->isPast(),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'resolved_at' => $row->resolved_at,
            ])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<int, int>
     */
    public function activeCounts(int $organizationId, array $companyIds, ?int $periodId = null): array
    {
        return DB::table('review_observations')->where('review_observations.organization_id', $organizationId)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('status', self::ACTIVE)->when($periodId !== null, fn (Builder $query) => $query->where('accounting_period_id', $periodId))
            ->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id')
            ->mapWithKeys(fn ($total, $companyId): array => [(int) $companyId => (int) $total])->all();
    }

    private function relatedHref(string $companyPublicId, string $type, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return match ($type) {
            'journal_entries' => '/accounting/companies/'.$companyPublicId.'?entry='.$id,
            'purchase_documents' => '/accounting/companies/'.$companyPublicId.'/purchases/documents/'.$id,
            'sales_invoices' => '/accounting/companies/'.$companyPublicId.'/sales/invoices/'.$id,
            'bank_accounts' => '/accounting/companies/'.$companyPublicId.'/banks',
            'vat_book_periods' => '/accounting/companies/'.$companyPublicId.'/vat-books',
            'fiscal_obligations' => '/tax/obligations/'.$id,
            default => null,
        };
    }
}
