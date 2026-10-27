<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Desktop\DesktopSyncService;
use App\Services\WorkManagement\CompanyDeletionService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Endpoints of the desktop app. It uses the same signed-in session as the web: the app embeds the web pages, so a person
 * who can sign in can sync, and only their own companies.
 */
class DesktopController extends Controller
{
    public function __construct(private WorkspaceContext $context, private DesktopSyncService $sync) {}

    /** POST desktop/devices: the app announces the computer it runs on. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => 'required|uuid', 'name' => 'required|string|max:120',
            'platform' => ['required', Rule::in(['windows', 'mac', 'linux'])], 'app_version' => 'nullable|string|max:20',
        ]);
        $device = $this->sync->register($this->organization($request), $request->user(), $data);
        abort_if($device->revoked_at !== null, 410, 'Este equipo fue desconectado de Atlantia Finance. Su copia sin conexión se borrará.');

        return response()->json(['device_uuid' => $device->device_uuid, 'max_offline_days' => DesktopSyncService::MAX_OFFLINE_DAYS]);
    }

    /** GET desktop/snapshot: the encrypted offline copy is built from this answer. */
    public function snapshot(Request $request): JsonResponse
    {
        $data = $request->validate(['device_uuid' => 'required|uuid']);
        $organization = $this->organization($request);
        $device = $this->sync->activeDevice($organization, $request->user(), $data['device_uuid']);

        return response()->json($this->sync->snapshot($organization, $request->user(), $device))->header('Cache-Control', 'no-store');
    }

    /** POST desktop/captures: what was captured without connection, to become drafts. */
    public function captures(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => 'required|uuid',
            'captures' => 'required|array|max:200',
            'captures.*.id' => 'required|uuid',
            'captures.*.company' => 'required|uuid',
            'captures.*.kind' => ['required', Rule::in(array_keys(DesktopSyncService::KINDS))],
            'captures.*.captured_at' => 'required|date',
            'captures.*.payload' => 'required|array',
        ]);
        $organization = $this->organization($request);
        $device = $this->sync->activeDevice($organization, $request->user(), $data['device_uuid']);

        return response()->json(['results' => $this->sync->upload($organization, $request->user(), $device, $data['captures'])]);
    }

    /** GET desktop: the person's computers with the app and the captures that arrived from them. */
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);
        $owner = CompanyDeletionService::isOwner($organization, $request->user());

        return Inertia::render('desktop/index', [
            'devices' => $this->sync->devices($organization, $request->user(), $owner),
            'captures' => $this->sync->captures($organization, $request->user()),
            'canManageFirm' => $owner,
            'maxOfflineDays' => DesktopSyncService::MAX_OFFLINE_DAYS,
            'downloads' => array_filter((array) config('desktop.downloads')),
        ]);
    }

    public function revoke(Request $request, int $device): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->sync->revoke($organization, $request->user(), $device, CompanyDeletionService::isOwner($organization, $request->user()));

        return back()->with('workspace_notice', 'Equipo desconectado. Borrará su copia sin conexión la próxima vez que se conecte.');
    }

    public function dismiss(Request $request, int $capture): RedirectResponse
    {
        $this->sync->dismiss($this->organization($request), $request->user(), $capture);

        return back()->with('workspace_notice', 'Captura marcada como revisada.');
    }

    private function organization(Request $request): Organization
    {
        return $this->context->organization($request->user());
    }
}
