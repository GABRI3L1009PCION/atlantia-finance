<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Luzma\LuzmaSwitch;
use App\Services\Luzma\LuzmaToolRegistry;
use App\Services\Platform\EntitlementResolverService;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\IntegrationManagementService;
use App\Services\Platform\JobMonitoringService;
use App\Services\Platform\LuzmaNeuralService;
use App\Services\Platform\LuzmaPlatformService;
use App\Services\Platform\MonitoringAlertService;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\PlatformHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;

/** Services, jobs and queues, integrations and Luzma. */
class OperationsController extends ControlCenterController
{
    public function services(Request $request, PlatformHealthService $health, MonitoringAlertService $alerts): Response
    {
        $this->staff($request, 'saas.services.view');
        $selected = (string) $request->query('service', '');

        return $this->render($request, 'services', [
            'services' => $health->snapshot(),
            'selected' => $selected,
            'history' => $selected === '' ? [] : DB::table('service_health_checks')->where('service', $selected)->orderByDesc('id')->limit(60)->get(['status', 'latency_ms', 'detail', 'checked_at'])->all(),
            'alerts' => $alerts->recent(),
            'alertChannels' => $alerts->channels(),
        ]);
    }

    public function runChecks(Request $request, PlatformHealthService $health, PlatformAuditService $audit): RedirectResponse
    {
        $user = $this->staff($request, 'saas.services.view');
        $results = $health->run();
        $audit->record($user, 'services.checked', 'platform', null, null, ['down' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'down'))]);

        return back()->with('workspace_notice', 'Chequeos ejecutados: '.count(array_filter($results, fn (array $r): bool => $r['status'] === 'healthy')).' de '.count($results).' servicios operativos.');
    }

    public function jobs(Request $request, JobMonitoringService $jobs): Response
    {
        $user = $this->staff($request, 'saas.jobs.view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'running', 'completed', 'failed', 'retrying', 'cancelled'])], 'search' => 'nullable|string|max:100']);

        return $this->render($request, 'jobs', [
            'queue' => $jobs->queueHealth(), 'failed_groups' => $jobs->failedGroups(), 'runs' => $jobs->runs($filters), 'scheduler' => $jobs->scheduler(),
            'filters' => $filters, 'can_retry' => $this->authorization->can($user, 'saas.jobs.retry'),
        ]);
    }

    public function retryJobs(Request $request, JobMonitoringService $jobs, PlatformAuditService $audit): RedirectResponse
    {
        $user = $this->staff($request, 'saas.jobs.retry');
        $data = $request->validate(['uuids' => 'required|array|min:1|max:200', 'uuids.*' => 'string|max:64']);
        $count = $jobs->retry($user, array_values($data['uuids']), $audit);

        return back()->with('workspace_notice', $count.' job(s) devueltos a la cola.');
    }

    public function integrations(Request $request, IntegrationManagementService $integrations): Response
    {
        $user = $this->staff($request, 'saas.integrations.view');

        return $this->render($request, 'integrations', [
            'integrations' => $integrations->all(),
            'tenant_integrations' => ['api_tokens' => DB::table('api_tokens')->whereNull('revoked_at')->count(), 'webhooks' => DB::table('webhook_endpoints')->where('status', 'active')->count(),
                'webhooks_disabled' => DB::table('webhook_endpoints')->where('status', 'disabled')->count(), 'deliveries_failed_24h' => DB::table('webhook_deliveries')->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
                'api_requests_24h' => DB::table('api_request_logs')->where('created_at', '>=', now()->subDay())->count(), 'api_errors_24h' => DB::table('api_request_logs')->where('created_at', '>=', now()->subDay())->where('status', '>=', 500)->count()],
            'can_manage' => $this->authorization->can($user, 'saas.integrations.manage'),
        ]);
    }

    public function integrationAction(Request $request, string $integration, string $action, IntegrationManagementService $integrations): RedirectResponse
    {
        $user = $this->staff($request, 'saas.integrations.manage');
        $reason = $this->confirmSensitive($request, $user);
        match ($action) {
            'secret' => $integrations->updateSecret($user, $integration, (string) $request->validate(['secret' => 'required|string|min:8|max:500'])['secret'], $reason),
            'enable' => $integrations->setEnabled($user, $integration, true, $reason),
            'disable' => $integrations->setEnabled($user, $integration, false, $reason),
            default => abort(404),
        };

        return back()->with('workspace_notice', $action === 'secret' ? 'Secreto actualizado. Nunca se mostrará completo.' : 'Integración actualizada.');
    }

    public function luzma(Request $request, LuzmaPlatformService $luzma, EntitlementResolverService $entitlements, LuzmaToolRegistry $registry): Response
    {
        $user = $this->staff($request, 'saas.luzma.view');
        $section = in_array($request->route('section'), ['usage', 'tools'], true) ? (string) $request->route('section') : 'status';

        return $this->render($request, 'luzma', [
            'section' => $section,
            'status' => $luzma->status(),
            'usage' => $section === 'usage' ? $luzma->usageByTenant($entitlements) : [],
            'tools' => $section === 'tools' ? $luzma->tools($registry) : [],
            'tool_states' => LuzmaPlatformService::TOOL_STATES,
            'can_manage' => $this->authorization->can($user, 'saas.luzma.manage'),
            'can_ask' => $this->authorization->can($user, 'saas.luzma.use'),
        ]);
    }

    /** The only way to turn Luzma off (or back on) for every firm: the SaaS administrator's switch. */
    public function luzmaSwitch(Request $request, LuzmaSwitch $switch): RedirectResponse
    {
        $user = $this->staff($request, 'saas.luzma.manage');
        $data = $request->validate(['enabled' => 'required|boolean', 'reason' => 'required|string|min:5|max:500', 'message' => 'nullable|string|max:255']);
        if (isset($data['message']) && trim((string) $data['message']) !== '') {
            app(GlobalConfigurationService::class)->set($user, 'luzma.disabled_message', (string) $data['message']);
        }
        $switch->set($user, (bool) $data['enabled'], (string) $data['reason']);

        return back()->with('workspace_notice', $data['enabled'] ? 'Luzma quedó encendida para todas las firmas.' : 'Luzma quedó apagada para todas las firmas.');
    }

    public function toolStatus(Request $request, string $tool, LuzmaPlatformService $luzma, LuzmaToolRegistry $registry): RedirectResponse
    {
        $user = $this->staff($request, 'saas.luzma.manage');
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(LuzmaPlatformService::TOOL_STATES))], 'reason' => 'required|string|min:5|max:500']);
        $luzma->setToolStatus($user, $registry, $tool, (string) $data['status'], (string) $data['reason']);

        return back()->with('workspace_notice', 'Estado de la herramienta actualizado para todas las organizaciones.');
    }

    /** The neural network of Luzma, drawn and alive: layers, activations of the real questions and retraining. */
    public function neural(Request $request, LuzmaNeuralService $neural): Response
    {
        $user = $this->staff($request, 'saas.luzma.view');

        return $this->render($request, 'luzma-neural', ['info' => $neural->info(), 'recent' => $neural->recent(0), 'can_manage' => $this->authorization->can($user, 'saas.luzma.manage')]);
    }

    public function neuralInspect(Request $request, LuzmaNeuralService $neural): JsonResponse
    {
        $this->staff($request, 'saas.luzma.view');
        $data = $request->validate(['text' => 'required|string|min:2|max:300']);
        $result = $neural->inspect($data['text']);

        return $result === null ? response()->json(['message' => 'La red neuronal no responde.'], 503) : response()->json($result);
    }

    public function neuralRecent(Request $request, LuzmaNeuralService $neural): JsonResponse
    {
        $this->staff($request, 'saas.luzma.view');

        return response()->json([...$neural->recent((int) $request->query('after', 0)), 'info' => $neural->info()]);
    }

    public function neuralTrain(Request $request, LuzmaNeuralService $neural): RedirectResponse
    {
        $user = $this->staff($request, 'saas.luzma.manage');
        $data = $request->validate(['use_feedback' => 'required|boolean', 'epochs' => 'required|integer|between:5,80']);
        $result = $neural->train($user, (bool) $data['use_feedback'], (int) $data['epochs']);

        return back()->with('workspace_notice', ($result['started'] ?? false) ? 'La red empezó a reentrenarse; verás el avance en vivo.' : 'No se pudo iniciar el entrenamiento (ya hay uno en curso o el servicio no responde).');
    }
}
