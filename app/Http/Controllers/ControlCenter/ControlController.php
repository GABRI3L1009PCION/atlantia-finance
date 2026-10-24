<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\LegalDocumentService;
use App\Services\Platform\NotificationTemplateService;
use App\Services\Platform\PlatformLogReader;
use App\Support\PlatformIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;
use stdClass;

/** SaaS audit, system logs and global configuration (settings and templates). */
class ControlController extends ControlCenterController
{
    public function audit(Request $request): Response
    {
        $this->staff($request, 'saas.audit.view');
        $filters = $request->validate(['action' => 'nullable|string|max:80', 'actor' => 'nullable|string|max:100', 'organization_id' => 'nullable|integer', 'sensitive' => 'nullable|boolean',
            'from' => 'nullable|date', 'to' => 'nullable|date']);
        $names = DB::table('organizations')->pluck('name', 'id');

        return $this->render($request, 'audit', [
            'filters' => $filters,
            'actions' => DB::table('platform_audit_events')->distinct()->orderBy('action')->pluck('action')->all(),
            'events' => array_values(DB::table('platform_audit_events')
                ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
                ->when($filters['actor'] ?? null, fn ($q, $actor) => $q->where('actor_name', 'ilike', '%'.$actor.'%'))
                ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
                ->when(! empty($filters['sensitive']), fn ($q) => $q->where('sensitive', true))
                ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
                ->orderByDesc('id')->limit(300)->get()->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'actor' => $row->actor_name, 'role' => $row->actor_role,
                    'entity' => trim(($row->entity_type ?? '').' '.($row->entity_id ?? '')), 'organization' => $row->organization_id === null ? null : ($names[$row->organization_id] ?? '#'.$row->organization_id),
                    'before' => json_decode((string) ($row->before ?? 'null'), true), 'after' => json_decode((string) ($row->after ?? 'null'), true), 'reason' => $row->reason,
                    'ip' => $row->ip, 'session' => $row->session_id, 'correlation_id' => $row->correlation_id, 'sensitive' => (bool) $row->sensitive, 'at' => Carbon::parse($row->created_at)->toIso8601String()])->all()),
        ]);
    }

    public function logs(Request $request, PlatformLogReader $logs): Response
    {
        $this->staff($request, 'saas.logs.view');
        $filters = $request->validate(['source' => ['nullable', Rule::in(['application', 'queue', 'integration', 'provider', 'security'])], 'severity' => ['nullable', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical'])],
            'search' => 'nullable|string|max:100', 'correlation_id' => 'nullable|string|max:64', 'organization_id' => 'nullable|integer', 'date' => 'nullable|date_format:Y-m-d']);

        return $this->render($request, 'logs', ['entries' => $logs->entries($filters), 'filters' => $filters, 'environment' => app()->environment(),
            'organizations' => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->all()]);
    }

    public function settings(Request $request, GlobalConfigurationService $settings, NotificationTemplateService $templates): Response
    {
        $user = $this->staff($request, 'saas.settings.view');

        return $this->render($request, 'settings', ['settings' => $settings->all(), 'groups' => GlobalConfigurationService::GROUPS, 'templates' => $templates->all(),
            'can_manage' => $this->authorization->can($user, 'saas.settings.manage')]);
    }

    public function updateSetting(Request $request, GlobalConfigurationService $settings): RedirectResponse
    {
        $user = $this->staff($request, 'saas.settings.manage');
        $data = $request->validate(['key' => ['required', Rule::in(array_keys(GlobalConfigurationService::SETTINGS))], 'value' => 'nullable']);
        if ($settings->isSensitive((string) $data['key'])) {
            $this->confirmSensitive($request, $user);
        }
        $settings->set($user, (string) $data['key'], $data['value'] ?? '');

        return back()->with('workspace_notice', 'Configuración actualizada.');
    }

    public function legal(Request $request, LegalDocumentService $legal): Response
    {
        $user = $this->staff($request, 'saas.settings.view');

        return $this->render($request, 'legal', ['documents' => $legal->overview(), 'can_manage' => $this->authorization->can($user, 'saas.settings.manage')]);
    }

    public function publishLegal(Request $request, string $slug, LegalDocumentService $legal): RedirectResponse
    {
        $user = $this->staff($request, 'saas.settings.manage');
        abort_unless(isset(LegalDocumentService::DOCUMENTS[$slug]), 404);
        $data = $request->validate(['title' => 'required|string|max:200', 'body' => 'required|string|min:50|max:200000']);
        $version = $legal->publish($user, $slug, (string) $data['title'], (string) $data['body']);

        return back()->with('workspace_notice', 'Publicada la versión '.$version.'. Los dueños de firma deberán aceptarla en su siguiente ingreso.');
    }

    public function updateTemplate(Request $request, string $template, NotificationTemplateService $templates): RedirectResponse
    {
        $user = $this->staff($request, 'saas.settings.manage');
        $data = $request->validate(['subject' => 'required|string|max:200', 'body' => 'required|string|max:10000']);
        $version = $templates->update($user, $template, (string) $data['subject'], (string) $data['body']);

        return back()->with('workspace_notice', 'Plantilla guardada como versión '.$version.'. La anterior se conserva.');
    }
}
