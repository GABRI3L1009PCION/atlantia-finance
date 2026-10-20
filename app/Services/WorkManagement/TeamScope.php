<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use Illuminate\Database\Query\Builder;

/**
 * The team of the firm is its staff. External client contacts hold a membership to reach their portal, but they are not
 * part of the team: they never count as active staff, never appear in the workload and never take a seat of the plan.
 */
class TeamScope
{
    /** Leaves out the users whose active role is the external client one. */
    public static function withoutClients(Builder $query, int $organizationId, string $userColumn = 'organization_user.user_id'): Builder
    {
        return $query->whereNotExists(fn (Builder $role) => $role->selectRaw('1')->from('user_role_assignments as team_ura')->join('workspace_roles as team_role', 'team_role.id', '=', 'team_ura.workspace_role_id')
            ->whereColumn('team_ura.user_id', $userColumn)->where('team_ura.organization_id', $organizationId)->where('team_ura.status', 'active')->whereNull('team_ura.deleted_at')
            ->where('team_role.code', 'client_user'));
    }
}
