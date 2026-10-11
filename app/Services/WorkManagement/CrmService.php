<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/** The firm's commercial view of its clients: pipeline, contracts, renewals and interactions. Money is in cents of quetzales. */
class CrmService
{
    public const STAGES = ['prospect' => 'Prospectos', 'contact' => 'Contacto', 'proposal' => 'Propuesta', 'negotiation' => 'Negociación', 'won' => 'Cierre ganado'];

    public function __construct(private WorkDashboardService $dashboard, private AccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization, User $user): array
    {
        $executive = $this->dashboard->executiveSnapshot($organization, $user);
        $companies = collect($executive['companies'])->keyBy('id');
        $today = now()->toDateString();

        $contracts = DB::table('crm_contracts')->where('organization_id', $organization->id)->whereIn('company_id', $companies->keys()->all())->orderBy('renews_on')->get()->groupBy('company_id');
        $prospects = DB::table('crm_prospects')->where('organization_id', $organization->id)->orderByDesc('id')->get();
        $users = DB::table('users')->whereIn('id', $prospects->pluck('owner_id')->merge($contracts->flatten()->pluck('owner_id'))->filter()->unique()->all())->pluck('name', 'id');
        $overdue = DB::table('fiscal_obligations')->whereIn('company_id', $companies->keys()->all())->whereNull('filed_on')->whereNull('completed_at')->where('due_on', '<', $today)
            ->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->groupBy('company_id')->pluck('total', 'company_id');
        $interactions = DB::table('crm_interactions')->leftJoin('users', 'users.id', '=', 'crm_interactions.user_id')->leftJoin('companies', 'companies.id', '=', 'crm_interactions.company_id')->leftJoin('crm_prospects', 'crm_prospects.id', '=', 'crm_interactions.crm_prospect_id')
            ->where('crm_interactions.organization_id', $organization->id)->orderByDesc('crm_interactions.occurred_at')->limit(60)
            ->get(['crm_interactions.*', 'users.name as user_name', 'companies.name as company_name', 'crm_prospects.name as prospect_name']);

        $clients = $companies->map(function (array $company) use ($contracts, $overdue, $users): array {
            $contract = ($contracts->get($company['id']) ?? collect())->first();
            $late = (int) ($overdue[$company['id']] ?? 0) + (int) ($company['overdue_tasks'] ?? 0);
            $status = $contract === null ? 'no_contract' : ($contract->status === 'active' && $late > 0 ? 'at_risk' : $contract->status);

            return [
                'kind' => 'client',
                'id' => (int) $company['id'],
                'name' => $company['name'],
                'location' => $company['office_name'],
                'office' => $company['office_name'],
                'owner' => $contract?->owner_id ? ($users[$contract->owner_id] ?? null) : $company['accountant'],
                'status' => $status,
                'services' => $contract?->services,
                'fee_cents' => (int) ($contract->monthly_fee_cents ?? 0),
                'renews_on' => $contract?->renews_on,
                'risk' => $late > 0 ? 'high' : (($company['pending_tasks'] ?? 0) > 5 ? 'medium' : 'low'),
                'contract_id' => $contract?->id,
            ];
        })->values();

        $leads = $prospects->whereNotIn('stage', ['won', 'lost'])->map(fn (stdClass $prospect): array => [
            'kind' => 'prospect',
            'id' => (int) $prospect->id,
            'name' => $prospect->name,
            'location' => $prospect->contact_name,
            'office' => null,
            'owner' => $users[$prospect->owner_id] ?? null,
            'status' => 'prospect',
            'services' => null,
            'fee_cents' => (int) $prospect->estimated_value_cents,
            'renews_on' => $prospect->expected_close_on,
            'risk' => null,
            'stage' => $prospect->stage,
        ])->values();

        $active = $clients->whereIn('status', ['active', 'at_risk']);
        $cancelledRecently = DB::table('crm_contracts')->where('organization_id', $organization->id)->where('status', 'cancelled')->where('updated_at', '>=', now()->subYear())->count();

        return [
            'summary' => [
                'clients' => $clients->count(),
                'prospects' => $leads->count(),
                'onboarding' => $clients->where('status', 'onboarding')->count(),
                'at_risk' => $clients->where('status', 'at_risk')->count(),
                'retention' => $active->count() + $cancelledRecently > 0 ? (int) round($active->count() / ($active->count() + $cancelledRecently) * 1000) / 10 : null,
                'mrr_cents' => (int) $active->sum('fee_cents'),
            ],
            'pipeline' => collect(self::STAGES)->map(function (string $label, string $stage) use ($prospects): array {
                $items = $stage === 'won' ? $prospects->where('stage', 'won') : $prospects->where('stage', $stage);

                return ['stage' => $stage, 'label' => $label, 'count' => $items->count(), 'value_cents' => (int) $items->sum('estimated_value_cents')];
            })->values()->all(),
            'by_office' => $clients->groupBy('office')->map(fn ($group, string $office): array => ['name' => $office, 'value' => $group->count()])->sortByDesc('value')->values()->all(),
            'by_owner' => $clients->filter(fn (array $row): bool => $row['owner'] !== null)->groupBy('owner')->map(fn ($group, string $owner): array => ['name' => $owner, 'value' => $group->count()])->sortByDesc('value')->take(5)->values()->all(),
            'rows' => $clients->concat($leads)->values()->all(),
            'offices' => $clients->pluck('office')->unique()->sort()->values()->all(),
            'alerts' => $this->alerts($clients, $contracts),
            'renewals' => $contracts->flatten()->filter(fn (stdClass $contract): bool => $contract->status !== 'cancelled' && $contract->renews_on !== null && $contract->renews_on >= $today)->sortBy('renews_on')->take(6)->map(fn (stdClass $contract): array => [
                'company' => $companies[$contract->company_id]['name'] ?? '—',
                'services' => $contract->services,
                'renews_on' => $contract->renews_on,
            ])->values()->all(),
            'interactions' => $interactions->take(6)->map(fn (stdClass $item): array => [
                'user' => $item->user_name ?? 'Sistema',
                'about' => $item->company_name ?? $item->prospect_name,
                'summary' => $item->summary,
                'kind' => $item->kind,
                'at' => $item->occurred_at,
            ])->values()->all(),
            'companies' => $companies->map(fn (array $company): array => ['id' => (int) $company['id'], 'name' => $company['name']])->values()->all(),
            'prospect_options' => $prospects->whereNotIn('stage', ['won', 'lost'])->map(fn (stdClass $prospect): array => ['id' => (int) $prospect->id, 'name' => $prospect->name, 'stage' => $prospect->stage])->values()->all(),
            'staff' => DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organization->id)->where('organization_user.status', 'active')->orderBy('users.name')->get(['users.id', 'users.name'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => $row->name])->all(),
        ];
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $clients
     * @param  Collection<int|string, covariant Collection<int, covariant stdClass>>  $contracts
     * @return list<array{tone: string, title: string, detail: string, count: int}>
     */
    private function alerts($clients, $contracts): array
    {
        $soon = $contracts->flatten()->filter(fn (stdClass $contract): bool => $contract->status !== 'cancelled' && $contract->renews_on !== null && $contract->renews_on >= now()->toDateString() && $contract->renews_on <= now()->addDays(30)->toDateString())->count();
        $withoutContract = $clients->where('status', 'no_contract')->count();
        $risk = $clients->where('status', 'at_risk')->count();
        $onboarding = $clients->where('status', 'onboarding')->count();

        return array_values(array_filter([
            $withoutContract > 0 ? ['tone' => 'amber', 'title' => 'Clientes sin contrato registrado', 'detail' => $withoutContract.' empresa(s) no tienen servicio ni cuota mensual registrados.', 'count' => $withoutContract] : null,
            $soon > 0 ? ['tone' => 'amber', 'title' => 'Vencimientos próximos (30 días)', 'detail' => $soon.' contrato(s) por renovar.', 'count' => $soon] : null,
            $risk > 0 ? ['tone' => 'red', 'title' => 'Clientes en riesgo', 'detail' => $risk.' empresa(s) con atrasos requieren atención.', 'count' => $risk] : null,
            $onboarding > 0 ? ['tone' => 'blue', 'title' => 'Onboarding en proceso', 'detail' => $onboarding.' empresa(s) en incorporación.', 'count' => $onboarding] : null,
        ]));
    }

    /** @param array<string, mixed> $data */
    public function createProspect(Organization $organization, User $actor, array $data): int
    {
        $id = (int) DB::table('crm_prospects')->insertGetId([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'stage' => $data['stage'],
            'estimated_value_cents' => (int) round(((float) ($data['estimated_value'] ?? 0)) * 100),
            'expected_close_on' => $data['expected_close_on'] ?? null,
            'owner_id' => $data['owner_id'] ?? $actor->id,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'crm.prospect_created', 'crm_prospects', $id, ['name' => $data['name']]);

        return $id;
    }

    public function moveProspect(Organization $organization, User $actor, int $prospectId, string $stage): void
    {
        $updated = DB::table('crm_prospects')->where('organization_id', $organization->id)->where('id', $prospectId)->update(['stage' => $stage, 'updated_at' => now()]);
        abort_if($updated === 0, 404);
        $this->accounting->audit($organization, $actor, 'crm.prospect_moved', 'crm_prospects', $prospectId, ['stage' => $stage]);
    }

    /** @param array<string, mixed> $data */
    public function saveContract(Organization $organization, User $actor, array $data): int
    {
        abort_unless(DB::table('companies')->where('organization_id', $organization->id)->where('id', $data['company_id'])->exists(), 422);
        $payload = [
            'services' => $data['services'],
            'monthly_fee_cents' => (int) round(((float) $data['monthly_fee']) * 100),
            'starts_on' => $data['starts_on'],
            'renews_on' => $data['renews_on'] ?? null,
            'status' => $data['status'],
            'owner_id' => $data['owner_id'] ?? null,
            'updated_at' => now(),
        ];
        $existing = DB::table('crm_contracts')->where('organization_id', $organization->id)->where('company_id', $data['company_id'])->where('status', '!=', 'cancelled')->value('id');

        if ($existing) {
            DB::table('crm_contracts')->where('id', $existing)->update($payload);
            $id = (int) $existing;
        } else {
            $id = (int) DB::table('crm_contracts')->insertGetId([...$payload, 'organization_id' => $organization->id, 'company_id' => $data['company_id'], 'created_at' => now()]);
        }

        $this->accounting->audit($organization, $actor, 'crm.contract_saved', 'crm_contracts', $id, ['company_id' => $data['company_id']]);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function logInteraction(Organization $organization, User $actor, array $data): int
    {
        $id = (int) DB::table('crm_interactions')->insertGetId([
            'organization_id' => $organization->id,
            'company_id' => $data['company_id'] ?? null,
            'crm_prospect_id' => $data['crm_prospect_id'] ?? null,
            'user_id' => $actor->id,
            'kind' => $data['kind'],
            'summary' => $data['summary'],
            'occurred_at' => now(),
            'next_action' => $data['next_action'] ?? null,
            'next_action_on' => $data['next_action_on'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'crm.interaction_logged', 'crm_interactions', $id, ['kind' => $data['kind']]);

        return $id;
    }
}
