<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Who may approve a piece of work. A small office (the plan holds two people at most) runs in SMALL_OFFICE_MODE: the
 * owner who prepared something may finish it without sending a review to themselves, and the audit trail records the
 * policy that allowed it. Larger offices require a different person to review.
 */
class ReviewPolicyService
{
    public const REQUIRED_SEPARATE_REVIEWER = 'REQUIRED_SEPARATE_REVIEWER';

    public const SELF_REVIEW_ALLOWED = 'SELF_REVIEW_ALLOWED';

    public const NO_REVIEW_REQUIRED_FOR_AUTHORIZED_ROLE = 'NO_REVIEW_REQUIRED_FOR_AUTHORIZED_ROLE';

    public function __construct(private WorkspaceContext $context) {}

    public function policy(Organization $organization): string
    {
        return $this->context->limit($organization, 'users') <= 2 ? self::SELF_REVIEW_ALLOWED : self::REQUIRED_SEPARATE_REVIEWER;
    }

    /** Whether the actor may finish work they prepared themselves, without a separate review. */
    public function mayFinishOwnWork(Organization $organization, User $actor, ?int $preparerId): bool
    {
        if ($preparerId === null || $preparerId !== $actor->id || $this->policy($organization) === self::REQUIRED_SEPARATE_REVIEWER) {
            return false;
        }

        return DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $actor->id)->where('status', 'active')->where('role', 'owner')->exists();
    }
}
