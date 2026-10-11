<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class CompanyPeriodReviewService
{
    public function __construct(
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    public function submit(Organization $organization, User $actor, int $companyId, int $periodId, ?int $reviewerId = null): int
    {
        $current = DB::table('company_period_reviews')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->value('status');
        if (in_array($current, ['ready_for_review', 'in_review', 'first_level_approved', 'approved'], true)) {
            throw ValidationException::withMessages(['review' => 'El período ya fue enviado a revisión o está aprobado; no se puede volver a enviar.']);
        }

        return $this->upsertReview($organization, $actor, $companyId, $periodId, 'ready_for_review', [
            'submitted_by' => $actor->id,
            'reviewer_id' => $reviewerId,
            'submitted_at' => now(),
            'fiscal_status' => null,
            'first_level_approved_by' => null,
            'first_level_approved_at' => null,
        ], 'company_period.submitted_for_review');
    }

    /**
     * First level approval by the accountant in charge. With the office on a single level workflow it is the accounting
     * approval; on a double level workflow the period waits for the general accountant (upper review).
     */
    public function approveFirstLevel(Organization $organization, User $actor, int $reviewId, ?string $comment = null, ?string $ip = null): string
    {
        $review = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $reviewId)->whereNull('deleted_at')->firstOrFail();
        $workflow = (string) DB::table('offices')->where('id', $review->office_id)->value('review_workflow');

        if ($workflow !== 'double_level') {
            $this->approve($organization, $actor, $reviewId, $comment, $ip);

            return 'approved';
        }

        $upperReviewer = $this->upperReviewer($organization->id, (int) $review->office_id, (int) $review->company_id, $actor->id);
        $this->transition($organization, $actor, $reviewId, ['in_review'], 'first_level_approved', [
            'first_level_approved_by' => $actor->id,
            'first_level_approved_at' => now(),
            'approval_comment' => $comment,
            'approved_ip' => $ip,
            'upper_reviewer_id' => $upperReviewer,
        ], 'company_period.first_level_approved');
        $companyName = (string) DB::table('companies')->where('id', $review->company_id)->value('name');
        $this->notifications->notify($organization->id, $upperReviewer, $actor->id, 'review.upper_requested', 'Revisión superior pendiente: '.$companyName, $comment, 'company_period_reviews', $reviewId);

        return 'first_level_approved';
    }

    /** Upper (second level) approval by the general accountant; the period then waits for the fiscal review. */
    public function approveUpper(Organization $organization, User $actor, int $reviewId, ?string $comment = null, ?string $ip = null): void
    {
        $review = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $reviewId)->whereNull('deleted_at')->firstOrFail();
        if ((int) $review->first_level_approved_by === $actor->id) {
            throw ValidationException::withMessages(['status' => 'La revisión superior debe hacerla una persona distinta a quien aprobó el primer nivel.']);
        }

        $this->transition($organization, $actor, $reviewId, ['first_level_approved'], 'approved', [
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'approval_comment' => $comment,
            'approved_ip' => $ip,
            'fiscal_status' => 'pending',
            'fiscal_reviewed_by' => null,
            'fiscal_reviewed_at' => null,
        ], 'company_period.upper_approved');
    }

    /** General accountant of the office (or organization-wide) that is not the first level approver. */
    private function upperReviewer(int $organizationId, int $officeId, int $companyId, int $exceptUserId): ?int
    {
        $assigned = DB::table('company_assignments')->where('company_id', $companyId)->where('assignment_role', 'general_accountant')->where('status', 'active')->whereNull('deleted_at')
            ->where('user_id', '!=', $exceptUserId)->value('user_id');
        if ($assigned !== null) {
            return (int) $assigned;
        }

        $officeGeneral = DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organizationId)->where('ura.status', 'active')->whereNull('ura.deleted_at')->where('role.code', 'general_accountant')
            ->where(fn ($query) => $query->whereNull('ura.office_id')->orWhere('ura.office_id', $officeId))
            ->where('ura.user_id', '!=', $exceptUserId)->orderBy('ura.id')->value('ura.user_id');

        return $officeGeneral === null ? null : (int) $officeGeneral;
    }

    public function start(Organization $organization, User $actor, int $reviewId): void
    {
        $this->transition($organization, $actor, $reviewId, ['ready_for_review'], 'in_review', ['review_started_at' => now()], 'company_period.review_started');
    }

    public function requestChanges(Organization $organization, User $actor, int $reviewId, string $message): void
    {
        $this->transition($organization, $actor, $reviewId, ['in_review', 'first_level_approved', 'approved'], 'changes_requested', ['changes_requested_at' => now(), 'metadata' => json_encode(['last_observation' => $message], JSON_THROW_ON_ERROR)], 'company_period.changes_requested');
    }

    /** Accounting approval: records who, when, from where and why; the period then waits for the fiscal review. */
    public function approve(Organization $organization, User $actor, int $reviewId, ?string $comment = null, ?string $ip = null): void
    {
        $this->transition($organization, $actor, $reviewId, ['in_review'], 'approved', [
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'approval_comment' => $comment,
            'approved_ip' => $ip,
            'fiscal_status' => 'pending',
            'fiscal_reviewed_by' => null,
            'fiscal_reviewed_at' => null,
        ], 'company_period.approved');
    }

    /** Fiscal approval (IVA, ISR, FEL, withholdings) of an accounting-approved period; after it the period can close. */
    public function approveFiscal(Organization $organization, User $actor, int $reviewId, ?string $comment = null): void
    {
        DB::transaction(function () use ($organization, $actor, $reviewId, $comment): void {
            $review = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $reviewId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($review->status !== 'approved' || $review->fiscal_status === 'approved') {
                throw ValidationException::withMessages(['status' => 'La revisión fiscal solo procede sobre un período con contabilidad aprobada y fiscal pendiente.']);
            }

            DB::table('company_period_reviews')->where('id', $reviewId)->update(['fiscal_status' => 'approved', 'fiscal_reviewed_by' => $actor->id, 'fiscal_reviewed_at' => now(), 'updated_at' => now()]);
            $this->syncWorkStatus($organization->id, (int) $review->office_id, (int) $review->company_id, (int) $review->accounting_period_id, 'approved', $actor->id);
            $this->accounting->audit($organization, $actor, 'company_period.fiscal_approved', 'company_period_reviews', $reviewId, ['from' => 'fiscal_pending', 'to' => 'fiscal_approved', 'comment' => $comment], (int) $review->office_id);
            $companyName = (string) DB::table('companies')->where('id', $review->company_id)->value('name');
            $this->notifications->notify($organization->id, $review->submitted_by === null ? null : (int) $review->submitted_by, $actor->id, 'review.fiscal_approved', 'Fiscal aprobado: '.$companyName, $comment, 'company_period_reviews', $reviewId);
        });
    }

    /** Fiscal observation: the period goes back to correction with a mandatory explanation. */
    public function requestFiscalChanges(Organization $organization, User $actor, int $reviewId, string $message): void
    {
        $this->transition($organization, $actor, $reviewId, ['approved'], 'changes_requested', [
            'changes_requested_at' => now(),
            'fiscal_status' => 'changes_requested',
            'fiscal_reviewed_by' => $actor->id,
            'fiscal_reviewed_at' => now(),
            'metadata' => json_encode(['last_observation' => $message, 'stage' => 'fiscal'], JSON_THROW_ON_ERROR),
        ], 'company_period.fiscal_changes_requested');
    }

    /** Moves a pending review to another reviewer of the same office (e.g. to balance the review queue). */
    public function reassignReviewer(Organization $organization, User $actor, int $reviewId, int $reviewerId, ?string $reason = null): void
    {
        DB::transaction(function () use ($organization, $actor, $reviewId, $reviewerId, $reason): void {
            $review = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $reviewId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if (! in_array($review->status, ['ready_for_review', 'in_review', 'changes_requested'], true)) {
                throw ValidationException::withMessages(['reviewer_id' => 'Solo se reasignan revisiones pendientes.']);
            }
            $isOfficeMember = DB::table('office_user')->where('office_id', $review->office_id)->where('user_id', $reviewerId)->where('status', 'active')->exists()
                && DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $reviewerId)->where('status', 'active')->exists();
            if (! $isOfficeMember) {
                throw ValidationException::withMessages(['reviewer_id' => 'El revisor debe ser un colaborador activo de la oficina.']);
            }

            DB::table('company_period_reviews')->where('id', $reviewId)->update(['reviewer_id' => $reviewerId, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'review.reassigned', 'company_period_reviews', $reviewId, ['from' => $review->reviewer_id, 'to' => $reviewerId, 'reason' => $reason], (int) $review->office_id);
            $companyName = (string) DB::table('companies')->where('id', $review->company_id)->value('name');
            $this->notifications->notify($organization->id, $reviewerId, $actor->id, 'review.assigned', 'Te asignaron la revisión de '.$companyName, $reason, 'company_period_reviews', $reviewId);
        });
    }

    /** @param array<string, mixed> $values */
    private function upsertReview(Organization $organization, User $actor, int $companyId, int $periodId, string $status, array $values, string $auditAction): int
    {
        $company = $this->company($organization->id, $companyId);
        DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->firstOrFail();

        return DB::transaction(function () use ($organization, $actor, $company, $periodId, $status, $values, $auditAction): int {
            $existing = DB::table('company_period_reviews')->where('company_id', $company->id)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->first();
            $payload = [
                'organization_id' => $organization->id,
                'office_id' => $company->office_id,
                'company_id' => $company->id,
                'accounting_period_id' => $periodId,
                'status' => $status,
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                ...$values,
            ];

            if ($existing) {
                DB::table('company_period_reviews')->where('id', $existing->id)->update($payload);
                $id = (int) $existing->id;
            } else {
                $id = DB::table('company_period_reviews')->insertGetId([...$payload, 'created_at' => now()]);
            }

            $this->syncWorkStatus($organization->id, $company->office_id, $company->id, $periodId, $status, $actor->id);
            $this->accounting->audit($organization, $actor, $auditAction, 'company_period_reviews', $id, ['status' => $status]);

            $reviewerId = $values['reviewer_id'] ?? null;
            $this->notifications->notify($organization->id, $reviewerId === null ? null : (int) $reviewerId, $actor->id, 'review.submitted', 'Cierre listo para revisión: '.$company->name, null, 'company_period_reviews', $id);

            return $id;
        });
    }

    /**
     * @param  list<string>  $allowedFrom
     * @param  array<string, mixed>  $values
     */
    private function transition(Organization $organization, User $actor, int $reviewId, array $allowedFrom, string $status, array $values, string $auditAction): void
    {
        DB::transaction(function () use ($organization, $actor, $reviewId, $allowedFrom, $status, $values, $auditAction): void {
            $review = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $reviewId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if (! in_array($review->status, $allowedFrom, true)) {
                throw ValidationException::withMessages(['status' => 'La revisión no permite esa transición.']);
            }

            DB::table('company_period_reviews')->where('id', $reviewId)->update(['status' => $status, 'updated_at' => now(), ...$values]);
            $this->syncWorkStatus($organization->id, (int) $review->office_id, (int) $review->company_id, (int) $review->accounting_period_id, $status, $actor->id);
            $this->accounting->audit($organization, $actor, $auditAction, 'company_period_reviews', $reviewId, ['from' => $review->status, 'to' => $status]);

            $companyName = (string) DB::table('companies')->where('id', $review->company_id)->value('name');
            $submitter = $review->submitted_by === null ? null : (int) $review->submitted_by;
            if ($status === 'changes_requested') {
                $message = json_decode((string) ($values['metadata'] ?? '{}'), true);
                if (is_array($message) && isset($message['last_observation'])) {
                    $this->accounting->audit($organization, $actor, 'company_period.observation_added', 'company_period_reviews', $reviewId, ['observation' => $message['last_observation'], 'stage' => $message['stage'] ?? 'accounting'], (int) $review->office_id);
                }
            }
            match ($status) {
                'changes_requested' => $this->notifications->notify($organization->id, $submitter, $actor->id, 'review.changes_requested', 'Observaciones en el cierre de '.$companyName, null, 'company_period_reviews', $reviewId),
                'approved' => $this->notifications->notify($organization->id, $submitter, $actor->id, 'review.approved', 'Cierre aprobado: '.$companyName, null, 'company_period_reviews', $reviewId),
                default => null,
            };
        });
    }

    private function syncWorkStatus(int $organizationId, int $officeId, int $companyId, int $periodId, string $reviewStatus, int $actorId): void
    {
        $tasks = DB::table('work_tasks')
            ->where('work_tasks.organization_id', $organizationId)
            ->where('company_id', $companyId)
            ->where('accounting_period_id', $periodId)
            ->whereNull('deleted_at');
        $total = (clone $tasks)->count();
        $completed = (clone $tasks)->whereIn('status', ['approved', 'completed'])->count();
        $pending = (clone $tasks)->whereNotIn('status', ['approved', 'completed', 'cancelled'])->count();
        $ready = (clone $tasks)->whereIn('status', ['ready_for_review', 'in_review', 'approved'])->count();
        $blocked = (clone $tasks)->where('status', 'blocked')->count();
        $status = match ($reviewStatus) {
            'ready_for_review' => 'ready_for_review',
            'in_review', 'first_level_approved' => 'in_review',
            'changes_requested' => 'changes_requested',
            'approved' => 'approved',
            default => $total === 0 ? 'not_started' : ($pending === 0 ? 'ready_to_close' : 'in_progress'),
        };

        DB::table('company_period_work_statuses')->updateOrInsert(
            ['company_id' => $companyId, 'accounting_period_id' => $periodId],
            [
                'organization_id' => $organizationId,
                'office_id' => $officeId,
                'status' => $status,
                'progress' => $total === 0 ? 0 : (int) round(($completed / $total) * 100),
                'pending_tasks' => $pending,
                'ready_tasks' => $ready,
                'blocked_tasks' => $blocked,
                'updated_by' => $actorId,
                'metadata' => json_encode(['review_status' => $reviewStatus], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function company(int $organizationId, int $companyId): stdClass
    {
        return DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->where('companies.id', $companyId)
            ->select('companies.id', 'companies.office_id', 'companies.name')
            ->firstOrFail();
    }
}
