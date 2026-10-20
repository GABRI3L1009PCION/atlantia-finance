<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationPreferenceService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Per-person alert preferences: channel, frequency, lead days and extra recipients, general or per company. */
class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferenceService $preferences, private WorkspaceContext $context) {}

    public function edit(Request $request): Response
    {
        $organization = $this->context->organization($request->user());

        return Inertia::render('settings/notifications', ['preferences' => $this->preferences->forUser($organization, $request->user()), 'frequencies' => NotificationPreferenceService::FREQUENCIES,
            'companies' => DB::table('companies')->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(NotificationPreferenceService::KINDS))], 'company_id' => 'nullable|integer', 'in_app' => 'required|boolean', 'email' => 'required|boolean',
            'frequency' => ['required', Rule::in(array_keys(NotificationPreferenceService::FREQUENCIES))], 'lead_days' => 'required|integer|between:0,60', 'recipients' => 'nullable|string|max:500']);
        $this->preferences->save($organization, $request->user(), $data['kind'], isset($data['company_id']) ? (int) $data['company_id'] : null, $data);

        return back()->with('workspace_notice', 'Preferencias de alertas guardadas.');
    }
}
