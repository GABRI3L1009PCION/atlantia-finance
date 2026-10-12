<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Tenancy\CurrentOrganizationResolver;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lets a user who belongs to several accounting firms choose the one they are working in.
 * The choice lives in the session and drives the Row Level Security scope of every later request.
 */
class OrganizationSwitchController extends Controller
{
    public function __construct(private TenantDatabaseContext $context, private CurrentOrganizationResolver $organizations) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['organization' => ['required', 'uuid']]);
        $user = $request->user();

        $organization = $this->context->runAsSystem(
            fn (): ?array => $this->organizations->switchable($user)->firstWhere('public_id', $data['organization']),
        );
        abort_if($organization === null, 404);

        $user->forceFill(['current_organization_id' => $organization['id']])->save();
        $this->context->useOrganization($organization['id'], $user->id);

        DB::table('audit_logs')->insert([
            'organization_id' => $organization['id'],
            'user_id' => $user->id,
            'action' => 'organization.switched',
            'auditable_type' => 'organizations',
            'auditable_id' => $organization['id'],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'occurred_at' => now(),
        ]);

        return $organization['is_subscribed']
            ? to_route('dashboard')->with('status', 'Ahora trabajas en '.$organization['name'].'.')
            : to_route('billing.checkout.show');
    }
}
