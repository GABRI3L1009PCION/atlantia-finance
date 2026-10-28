<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Accounting\ConsolidationService;
use App\Services\AccountingService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Groups of companies and their consolidated financial statements. */
class ConsolidationController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting, private ConsolidationService $consolidation) {}

    public function index(Request $request): Response
    {
        [$organization, $companies] = $this->scope($request);
        $groupId = $request->integer('group') ?: (DB::table('company_groups')->where('organization_id', $organization->id)->orderBy('name')->value('id'));
        $dates = validator(['from' => $request->query('from', now()->startOfYear()->toDateString()), 'to' => $request->query('to', now()->toDateString())],
            ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $group = $groupId === null ? null : $this->consolidation->find($organization, (int) $groupId);
        $statement = null;
        if ($group !== null) {
            $members = [(int) $group->parent_company_id, ...DB::table('company_group_members')->where('company_group_id', $group->id)->pluck('company_id')->map(fn ($id): int => (int) $id)->all()];
            // Consolidating needs access to every company of the group.
            abort_unless(array_diff($members, $companies->pluck('id')->all()) === [], 403);
            $statement = $this->consolidation->statement($group, $dates['from'], $dates['to']);
        }

        return Inertia::render('accounting/consolidation', [
            'groups' => $this->consolidation->groups($organization),
            'group' => $group === null ? null : ['id' => (int) $group->id, 'name' => (string) $group->name, 'parent_company_id' => (int) $group->parent_company_id,
                'investment_account_code' => $group->investment_account_code,
                'members' => DB::table('company_group_members')->where('company_group_id', $group->id)->get(['company_id', 'ownership_bp'])->map(fn ($m): array => ['company_id' => (int) $m->company_id, 'ownership' => (int) $m->ownership_bp / 100])->all()],
            'statement' => $statement,
            'companies' => $companies->map(fn ($c): array => ['id' => (int) $c->id, 'name' => (string) $c->name])->values()->all(),
            'filters' => $dates,
            'canManage' => $organization->pivot->role === 'owner',
        ]);
    }

    public function save(Request $request, ?int $group = null): RedirectResponse
    {
        [$organization, $companies] = $this->scope($request);
        abort_unless($organization->pivot->role === 'owner', 403);
        $data = $request->validate(['name' => 'required|string|max:150', 'parent_company_id' => 'required|integer', 'investment_account_code' => 'nullable|string|max:20',
            'members' => 'required|array|min:1|max:50', 'members.*.company_id' => 'required|integer', 'members.*.ownership' => 'required|numeric|min:0.01|max:100']);
        $id = DB::transaction(fn (): int => $this->consolidation->save($organization, $request->user(), $group, $data['name'], (int) $data['parent_company_id'],
            array_values(array_map(fn (array $member): array => ['company_id' => (int) $member['company_id'], 'ownership_bp' => (int) round((float) $member['ownership'] * 100)], $data['members'])),
            filled($data['investment_account_code'] ?? null) ? trim((string) $data['investment_account_code']) : null, array_values($companies->map(fn ($company): int => (int) $company->id)->all())));

        return redirect()->route('accounting.consolidation', ['group' => $id])->with('workspace_notice', 'Grupo guardado.');
    }

    public function export(Request $request, int $group, ReportExportService $exports): HttpResponse
    {
        [$organization, $companies] = $this->scope($request);
        $row = $this->consolidation->find($organization, $group);
        $members = [(int) $row->parent_company_id, ...DB::table('company_group_members')->where('company_group_id', $row->id)->pluck('company_id')->map(fn ($id): int => (int) $id)->all()];
        abort_unless(array_diff($members, $companies->pluck('id')->all()) === [], 403);
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'format' => 'nullable|in:pdf,xlsx']);
        $statement = $this->consolidation->statement($row, $data['from'], $data['to']);
        $money = fn (int $cents): string => number_format($cents / 100, 2);
        $rows = [];
        foreach ($statement['sections'] as $section) {
            $rows[] = [mb_strtoupper($section['label']), ...array_fill(0, count($statement['companies']) + 2, '')];
            foreach ($section['lines'] as $line) {
                $rows[] = [$line['code'].' '.$line['name'], ...array_map(fn (array $company): string => $money((int) ($line['companies'][$company['id']] ?? 0)), $statement['companies']),
                    $money($line['eliminations']), $money($line['consolidated'])];
            }
            $rows[] = ['Total '.mb_strtolower($section['label']), ...array_fill(0, count($statement['companies']) + 1, ''), $money($section['total'])];
        }
        $rows[] = ['Participación no controladora en el patrimonio', ...array_fill(0, count($statement['companies']) + 1, ''), $money($statement['non_controlling']['equity_cents'])];

        return $exports->download(['title' => 'Estados financieros consolidados', 'filename' => 'consolidado-'.$data['to'], 'company' => $statement['group']['name'],
            'scope' => 'Del '.$data['from'].' al '.$data['to'], 'note' => 'Saldos por empresa, eliminaciones intercompañía y consolidado (NIIF 10).',
            'headers' => ['Cuenta', ...array_column($statement['companies'], 'name'), 'Eliminaciones', 'Consolidado'], 'money' => [], 'rows' => $rows], $data['format'] ?? 'xlsx');
    }

    /** @return array{0: Organization, 1: Collection<int, \stdClass>} */
    private function scope(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'consolidated_dashboard');

        return [$organization, $this->accounting->companies($organization, $request->user())->orderBy('companies.name')->get(['companies.id', 'companies.name'])];
    }
}
