<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Opens a review observation in the inbox of whoever follows the link: the responsible sees it in their own panel
 * (assistant or fiscal), the reviewer in the accountant panel. Unrelated users get a 403.
 */
class ObservationRedirectController extends Controller
{
    public function __invoke(Request $request, int $observation, WorkspaceContext $context, WorkAuthorizationService $authorization): RedirectResponse
    {
        $organization = $context->organization($request->user());
        $user = $request->user();
        $row = DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->where('id', $observation)->first(['company_id', 'assigned_to', 'created_by']);
        abort_if($row === null, 404);
        $roles = DB::table('company_assignments')->where('company_id', $row->company_id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('assignment_role')->all();

        $target = match (true) {
            (int) $row->assigned_to === $user->id && in_array('tax_specialist', $roles, true) && $authorization->can($organization, $user, 'fiscal.dashboard.view') => '/tax/observations?observation=',
            (int) $row->assigned_to === $user->id && in_array('accounting_assistant', $roles, true) && $authorization->can($organization, $user, 'assistant.dashboard.view') => '/assistant/observations?observation=',
            in_array('accountant_in_charge', $roles, true) && $authorization->can($organization, $user, 'accountant.dashboard.view') => '/accountant/observations?observation=',
            default => null,
        };
        abort_if($target === null, 403);

        return redirect($target.$observation);
    }
}
