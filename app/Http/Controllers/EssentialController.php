<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\FiscalObligationChecklistService;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\Fiscal\FiscalObligationWorkspaceService;
use App\Services\Operations\EssentialPanelService;
use App\Services\Operations\EssentialTaskService;
use App\Services\Operations\ReviewObservationService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\ReviewPolicyService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Workspace of the Esencial plan. The owner runs the whole practice; the second seat works the clients, tasks and
 * obligations assigned to them. Detailed work happens in the company modules, which keep their own permissions.
 */
class EssentialController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private EssentialPanelService $panel,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds] = $this->resolve($request);

        return $this->render($request, 'essential/dashboard', $organization, $companyIds, ['panel' => $this->panel->dashboard($organization, $request->user(), $companyIds)]);
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds] = $this->resolve($request);

        return $this->render($request, 'essential/tasks', $organization, $companyIds, [
            'tasks' => $this->panel->tasks($organization, $request->user(), $companyIds, $request->only(['status', 'company_id', 'assigned_to', 'priority', 'search', 'page'])),
            'form' => $this->panel->taskForm($organization, $request->user(), $companyIds),
            'filters' => $request->only(['status', 'company_id', 'assigned_to', 'priority', 'search']),
        ]);
    }

    public function task(Request $request, int $task, EssentialTaskService $service): Response
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = $service->find($organization, $request->user(), $task);

        return $this->render($request, 'essential/task', $organization, $companyIds, ['detail' => $service->detail($organization, $request->user(), $row)]);
    }

    public function taskAction(Request $request, int $task, string $action, EssentialTaskService $service): RedirectResponse
    {
        [$organization] = $this->resolve($request);
        $row = $service->find($organization, $request->user(), $task);
        $data = $request->validate(['note' => 'nullable|string|max:1000', 'expected_on' => 'nullable|date|after_or_equal:today', 'wait_id' => 'nullable|integer', 'file' => 'nullable|file|max:10240|mimes:pdf,png,jpg,jpeg,xlsx,xls,csv']);

        return back()->with('workspace_notice', $service->perform($organization, $request->user(), $row, $action, [...$data, 'file' => $request->file('file')]));
    }

    public function taskLink(Request $request, int $task, EssentialTaskService $service): RedirectResponse
    {
        [$organization] = $this->resolve($request);
        $row = $service->find($organization, $request->user(), $task);
        $data = $request->validate(['depends_on' => 'required|integer', 'type' => ['required', Rule::in(['REQUIRES', 'BLOCKS', 'RELATED_TO'])]]);
        $service->link($organization, $request->user(), $row, (int) $data['depends_on'], $data['type']);

        return back()->with('workspace_notice', 'Relación agregada.');
    }

    public function taskUnlink(Request $request, int $task, int $dependency, EssentialTaskService $service): RedirectResponse
    {
        [$organization] = $this->resolve($request);
        $service->unlink($organization, $request->user(), $service->find($organization, $request->user(), $task), $dependency);

        return back()->with('workspace_notice', 'Relación quitada.');
    }

    public function obligations(Request $request, FiscalObligationGenerationService $generation): Response
    {
        [$organization, $companyIds] = $this->resolve($request);
        $generation->generate($organization, null, $companyIds);

        return $this->render($request, 'essential/obligations', $organization, $companyIds, [
            'obligations' => $this->panel->obligations($organization, $request->user(), $companyIds, $request->only(['status', 'company_id', 'page'])),
            'companies' => DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
            'filters' => $request->only(['status', 'company_id']),
        ]);
    }

    public function refreshObligations(Request $request, FiscalObligationGenerationService $generation): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $result = $generation->generate($organization, $request->user(), $companyIds);

        return back()->with('workspace_notice', 'Calendario fiscal actualizado: '.$result['created'].' obligación(es) nueva(s), '.$result['tasks'].' tarea(s) generada(s).');
    }

    public function obligation(Request $request, int $obligation, FiscalObligationWorkspaceService $workspace, ReviewPolicyService $policies, FiscalObligationChecklistService $checklist): Response
    {
        [$organization, $companyIds, $row] = $this->loadObligation($request, $obligation);
        $user = $request->user();
        $owner = $this->panel->isOwner($organization, $user);
        $can = $this->panel->obligationCan($organization, $row, $user, $owner, $this->panel->isStale($row));
        $mayFinish = $policies->mayFinishOwnWork($organization, $user, $row->assigned_to === null ? null : (int) $row->assigned_to);

        return $this->render($request, 'essential/obligation', $organization, $companyIds, [
            ...$workspace->payload($row),
            'can' => $can,
            'review_policy' => $policies->policy($organization),
            'may_finish_own' => $mayFinish && $row->status === 'in_progress',
            'is_preparer' => $owner || (int) $row->assigned_to === $user->id,
            'version' => $checklist->version($row),
            'also_editing' => $this->presence($obligation, $user),
            'confirm' => in_array($request->query('confirm'), ['submit', 'finalize'], true) ? $request->query('confirm') : null,
        ]);
    }

    /**
     * Who else opened this obligation in the last minutes, so two people do not prepare it at the same time without knowing.
     *
     * @return list<string>
     */
    private function presence(int $obligationId, User $user): array
    {
        $key = 'obligation-viewers:'.$obligationId;
        $viewers = array_filter((array) Cache::get($key, []), fn (array $viewer): bool => $viewer['at'] > now()->subMinutes(3)->getTimestamp() && $viewer['id'] !== $user->id);
        $mine = [$user->id => ['id' => $user->id, 'name' => $user->name, 'at' => now()->getTimestamp()]];
        Cache::put($key, $viewers + $mine, 300);

        return array_values(array_map(fn (array $viewer): string => $viewer['name'], $viewers));
    }

    /** Marks a manual requirement of the checklist as done (with a note and, when it asks for one, its file). */
    public function obligationCheck(Request $request, int $obligation, string $key, FiscalObligationChecklistService $checklist): RedirectResponse
    {
        [$organization, , $row] = $this->loadObligation($request, $obligation);
        abort_unless($this->panel->obligationCan($organization, $row, $request->user(), $this->panel->isOwner($organization, $request->user()), false)['submit'], 403);
        $checklist->assertVersion($row, $request->input('version'));
        $data = $request->validate(['note' => 'nullable|string|max:500', 'file' => 'nullable|file|max:10240|mimes:pdf,png,jpg,jpeg,xlsx,xls,csv']);
        $checklist->complete($organization, $request->user(), $row, $key, $data['note'] ?? null, $request->file('file'));

        return back()->with('workspace_notice', 'Requisito registrado.');
    }

    /** The preparer reports a requested change as corrected (after fixing the record at its source); the reviewer validates it. */
    public function obligationObservationCorrected(Request $request, int $obligation, int $observation, ReviewObservationService $observations): RedirectResponse
    {
        [$organization, , $row] = $this->loadObligation($request, $obligation);
        abort_unless($this->panel->obligationCan($organization, $row, $request->user(), $this->panel->isOwner($organization, $request->user()), false)['submit'], 403);
        abort_unless($this->observationsOf($organization, $obligation)->contains($observation), 404);
        $data = $request->validate(['note' => 'required|string|min:3|max:1000'], ['note.required' => 'Describe la corrección realizada.']);
        $observations->transition($organization, $request->user(), $observation, 'ready_for_review', $data['note']);

        return back()->with('workspace_notice', 'Corrección registrada; quien revisa la validará.');
    }

    public function obligationCheckReopen(Request $request, int $obligation, string $key, FiscalObligationChecklistService $checklist): RedirectResponse
    {
        [$organization, , $row] = $this->loadObligation($request, $obligation);
        abort_unless($this->panel->obligationCan($organization, $row, $request->user(), $this->panel->isOwner($organization, $request->user()), false)['submit'], 403);
        $checklist->reopen($organization, $request->user(), $row, $key);

        return back()->with('workspace_notice', 'Requisito reabierto.');
    }

    public function obligationAction(Request $request, int $obligation, string $action, FiscalObligationWorkflowService $workflow, FiscalObligationChecklistService $checklist, ReviewPolicyService $policies, ReviewObservationService $observations): RedirectResponse
    {
        [$organization, , $row] = $this->loadObligation($request, $obligation);
        $user = $request->user();
        $checklist->assertVersion($row, $request->input('version'));
        $can = $this->panel->obligationCan($organization, $row, $user, $this->panel->isOwner($organization, $user), $this->panel->isStale($row));
        $own = $policies->mayFinishOwnWork($organization, $user, $row->assigned_to === null ? null : (int) $row->assigned_to);

        switch ($action) {
            case 'start':
                abort_unless($can['start'], 403);
                $workflow->start($organization, $user, $obligation);
                $message = 'Obligación iniciada. Continúa con su preparación.';
                break;
            case 'submit':
            case 'finalize':
                abort_unless($can['submit'], 403);
                // Sending it (or finishing it) is only possible when the real checks say the work is complete.
                $this->assertReady($checklist->summary($row));
                if ($action === 'finalize') {
                    abort_unless($own, 403, 'Tu política de revisión requiere que otra persona la revise.');
                    $workflow->finalizeOwn($organization, $user, $obligation, $policies->policy($organization));
                    $message = 'Preparación finalizada. Ya puedes registrar la presentación.';
                } else {
                    $workflow->submit($organization, $user, $obligation);
                    $message = 'Obligación enviada a revisión.';
                }
                break;
            case 'approve':
                abort_unless($can['approve'], 403);
                $data = $request->validate(['comment' => 'nullable|string|max:1000']);
                $workflow->approve($organization, $user, $obligation, $data['comment'] ?? null, $own, $policies->policy($organization));
                // Approving validates the corrections the preparer reported (never the reviewer's own work on herself).
                foreach ($this->observationsOf($organization, $obligation, ['ready_for_review']) as $observationId) {
                    if ((int) DB::table('review_observations')->where('id', $observationId)->value('assigned_to') !== $user->id) {
                        $observations->transition($organization, $user, $observationId, 'resolved', 'Validada al aprobar la obligación.');
                    }
                }
                $message = 'Obligación aprobada. Falta registrar su presentación.';
                break;
            case 'changes':
                abort_unless($can['changes'], 403);
                $data = $request->validate([
                    'message' => 'required|string|min:5|max:1200', 'related_type' => ['nullable', Rule::in(['purchase_documents', 'sales_invoices', 'journal_entries'])], 'related_id' => 'nullable|integer|min:1',
                ], ['message.required' => 'Explica qué debe corregirse.']);
                [$type, $id, $label] = $this->relatedRecord($row, $data['related_type'] ?? null, isset($data['related_id']) ? (int) $data['related_id'] : null);
                $workflow->requestChanges($organization, $user, $obligation, $data['message'], $type, $id, $label);
                $message = 'Cambios solicitados.';
                break;
            case 'recalculate':
                abort_unless($can['recalculate'], 403);
                $workflow->recalculate($organization, $user, $obligation);
                $message = 'Obligación devuelta a preparación para recalcular con los datos actuales.';
                break;
            case 'complete':
                abort_unless($can['complete'], 403);
                $data = $request->validate([
                    'filed_on' => 'required|date|before_or_equal:today',
                    'filing_reference' => 'required|string|max:120',
                    'evidence' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg',
                    'notes' => 'nullable|string|max:1000',
                ], ['evidence.required' => 'Adjunta la constancia de presentación.', 'filing_reference.required' => 'Indica el número de formulario o referencia de la presentación.']);
                $workflow->complete($organization, $user, $obligation, (string) $data['filed_on'], (string) $data['filing_reference'], $request->file('evidence'), $data['notes'] ?? null);
                $message = 'Presentación registrada con su constancia.';
                break;
            default:
                abort(404);
        }

        return back()->with('workspace_notice', $message);
    }

    /**
     * The record a requested change points to must belong to the same company; the observation keeps the pointer so the
     * preparer corrects it at its source.
     *
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    private function relatedRecord(stdClass $obligation, ?string $type, ?int $id): array
    {
        if ($type === null || $id === null) {
            return [null, null, null];
        }
        $label = match ($type) {
            'purchase_documents' => DB::table($type)->where('id', $id)->where('company_id', $obligation->company_id)->value('number'),
            'sales_invoices' => DB::table($type)->where('id', $id)->where('company_id', $obligation->company_id)->value('number'),
            default => DB::table($type)->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('journal_entries.id', $id)->where('accounting_periods.company_id', $obligation->company_id)->value('journal_entries.number'),
        };
        if ($label === null) {
            throw ValidationException::withMessages(['related_id' => 'No encontramos ese registro en esta empresa.']);
        }

        return [$type, $id, ['purchase_documents' => 'Compra', 'sales_invoices' => 'Venta', 'journal_entries' => 'Asiento'][$type].' '.$label];
    }

    /**
     * Observations raised by the review of this obligation (about itself or about a record it points to).
     *
     * @param  list<string>|null  $statuses
     * @return Collection<int, int>
     */
    private function observationsOf(Organization $organization, int $obligation, ?array $statuses = null): Collection
    {
        return DB::table('review_observations')->where('review_observations.organization_id', $organization->id)
            ->where(fn ($query) => $query->where(fn ($own) => $own->where('related_type', 'fiscal_obligations')->where('related_id', $obligation))->orWhere(fn ($own) => $own->where('origin_type', 'fiscal_obligations')->where('origin_id', $obligation)))
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))->pluck('id')->map(fn ($id): int => (int) $id);
    }

    /** @param array{ready: bool, pending: list<string>, error: string|null} $readiness */
    private function assertReady(array $readiness): void
    {
        if ($readiness['error'] !== null) {
            throw ValidationException::withMessages(['readiness' => $readiness['error']]);
        }
        if (! $readiness['ready']) {
            throw ValidationException::withMessages(['readiness' => 'Aún no está lista. Faltan: '.implode('; ', $readiness['pending']).'.']);
        }
    }

    /** @return array{0: Organization, 1: list<int>, 2: stdClass} */
    private function loadObligation(Request $request, int $obligation): array
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->where('id', $obligation)->first();
        abort_if($row === null || ! in_array((int) $row->company_id, $companyIds, true), 404);

        return [$organization, $companyIds, $row];
    }

    public function invoicing(Request $request): Response
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->context->requireFeature($organization, 'basic_invoicing');

        return $this->render($request, 'essential/invoicing', $organization, $companyIds, [
            'invoices' => $this->panel->invoicing($organization, $companyIds, $request->only(['status', 'company_id', 'page'])),
            'filters' => $request->only(['status', 'company_id']),
        ]);
    }

    public function audit(Request $request): Response
    {
        [$organization, $companyIds] = $this->resolve($request, true);
        $this->context->requireFeature($organization, 'audit_log');

        return $this->render($request, 'essential/audit', $organization, $companyIds, [
            'log' => $this->panel->audit($organization, $request->only(['action', 'user_id', 'from', 'to', 'search', 'page'])),
            'filters' => $request->only(['action', 'user_id', 'from', 'to', 'search']),
        ]);
    }

    public function exportAudit(Request $request, ReportExportService $exports): HttpResponse
    {
        [$organization] = $this->resolve($request, true);
        $this->context->requireFeature($organization, 'audit_log');
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $rows = $this->panel->audit($organization, $request->only(['action', 'user_id', 'from', 'to', 'search']), null)['rows'];

        return $exports->download([
            'title' => 'Registro de auditoría',
            'filename' => 'auditoria-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($rows).' evento(s)',
            'note' => 'Generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.'.',
            'headers' => ['Fecha', 'Usuario', 'Acción', 'Registro', 'Detalle', 'IP'],
            'money' => [],
            'rows' => array_map(fn (array $row): array => [$row['at'], $row['actor'], $row['action'], trim(($row['entity'] ?? '').' '.($row['entity_id'] ?? '')), $row['detail'] ?? '', $row['ip'] ?? ''], $rows),
        ], $format);
    }

    public function backups(Request $request): Response
    {
        [$organization, $companyIds] = $this->resolve($request, true);
        $this->context->requireFeature($organization, 'automatic_backups');

        return $this->render($request, 'essential/backups', $organization, $companyIds, ['backups' => $this->panel->backups()]);
    }

    /** @return array{0: Organization, 1: list<int>} */
    private function resolve(Request $request, bool $ownerOnly = false): array
    {
        $organization = $this->context->organization($request->user());
        abort_if($ownerOnly && ! $this->panel->isOwner($organization, $request->user()), 403, 'Solo el propietario de la oficina puede ver esta sección.');

        return [$organization, $this->panel->companyIds($organization, $request->user())];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $props
     */
    private function render(Request $request, string $component, Organization $organization, array $companyIds, array $props): Response
    {
        $user = $request->user();
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $owner = $this->panel->isOwner($organization, $user);

        return Inertia::render($component, [
            'essential' => [
                'owner' => $owner,
                'solo' => $this->panel->soloOffice($organization),
                'badges' => [
                    'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])
                        ->where(fn ($query) => $query->where('assigned_to', $user->id)->orWhere('reviewer_id', $user->id))->count(),
                    'obligations' => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable'])
                        ->where('due_on', '<=', now()->addDays(7)->toDateString())->count(),
                ],
            ],
            ...$props,
        ]);
    }
}
