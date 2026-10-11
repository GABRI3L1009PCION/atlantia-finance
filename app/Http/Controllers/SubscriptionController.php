<?php

namespace App\Http\Controllers;

use App\Services\Platform\SubscriptionOverviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private SubscriptionOverviewService $subscription,
    ) {}

    /** GET office/subscription: the firm's active plan, usage, billing history and the plans on offer. */
    public function show(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view') || $this->authorization->can($organization, $request->user(), 'office.settings.manage'), 403);

        return Inertia::render('work/subscription', $this->subscription->overview($organization));
    }
}
