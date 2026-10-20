<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\WorkManagement\ProfitabilityService;
use App\Services\WorkManagement\TimeTrackingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Hours of the team per client and the profitability of each client of the firm. */
class ProfitabilityController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private ProfitabilityService $profitability,
        private TimeTrackingService $time,
    ) {}

    /** GET office/profitability: fee, hours and margin per client for a month. Whoever holds crm.manage also edits budgets and the cost per hour. */
    public function index(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.view'), 403);
        $filters = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return Inertia::render('work/profitability', [
            ...$this->profitability->forMonth($organization, $request->user(), $filters['month'] ?? now()->format('Y-m')),
            'canManage' => $this->authorization->can($organization, $request->user(), 'crm.manage'),
            'overrunThreshold' => ProfitabilityService::OVERRUN_THRESHOLD,
        ]);
    }

    public function budget(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['company_id' => 'required|integer', 'hours' => 'required|string|max:8']);
        abort_unless($this->reachable($request, (int) $data['company_id']), 404);
        $this->profitability->setBudget($organization, $request->user(), (int) $data['company_id'], $data['hours']);

        return back()->with('workspace_notice', 'Horas presupuestadas actualizadas.');
    }

    public function cost(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['office_id' => 'required|integer', 'cost' => 'required|string|max:12']);
        $this->profitability->setHourlyCost($organization, $request->user(), (int) $data['office_id'], $data['cost']);

        return back()->with('workspace_notice', 'Costo interno por hora actualizado. Aplica a las horas que se registren desde ahora.');
    }

    /** GET work/time: the person's own hours. */
    public function time(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.view'), 403);
        $filters = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = ($filters['month'] ?? now()->format('Y-m')).'-01';
        $from = Carbon::parse($month)->startOfMonth();

        return Inertia::render('work/time', [
            ...$this->time->mine($request->user(), $from->toDateString(), $from->copy()->endOfMonth()->toDateString()),
            'month' => $from->format('Y-m'), 'companies' => $this->time->companies($organization, $request->user()), 'activities' => TimeTrackingService::ACTIVITIES,
            'today' => now()->toDateString(), 'windowDays' => TimeTrackingService::WINDOW_DAYS,
        ]);
    }

    public function storeTime(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.view'), 403);
        $data = $request->validate(['company_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'hours' => 'required|string|max:8', 'activity' => ['nullable', Rule::in(array_keys(TimeTrackingService::ACTIVITIES))], 'note' => 'nullable|string|max:255']);
        $company = collect($this->time->companies($organization, $request->user()))->firstWhere('id', (int) $data['company_id']);
        abort_if($company === null, 404);
        $this->time->record($organization, $request->user(), (object) ['id' => $company['id'], 'office_id' => (int) DB::table('companies')->where('id', $company['id'])->value('office_id')], $data);

        return back()->with('workspace_notice', 'Horas registradas.');
    }

    public function destroyTime(Request $request, int $entry): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.view'), 403);
        $this->time->remove($request->user(), $entry);

        return back()->with('workspace_notice', 'Registro eliminado.');
    }

    private function manage(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.manage'), 403);

        return $organization;
    }

    private function reachable(Request $request, int $companyId): bool
    {
        $organization = $this->context->organization($request->user());

        return collect($this->time->companies($organization, $request->user()))->contains('id', $companyId);
    }
}
