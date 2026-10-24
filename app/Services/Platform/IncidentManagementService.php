<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Support\PlatformIssuer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Platform incidents (a problem that may affect many tenants): investigation → identified → mitigating → monitoring →
 * resolved → postmortem, with a timeline of updates and communications to all tenants, the affected ones or a plan.
 */
class IncidentManagementService
{
    public const STATUS_LABELS = ['investigating' => 'Investigando', 'identified' => 'Identificado', 'mitigating' => 'Mitigando', 'monitoring' => 'Monitoreando', 'resolved' => 'Resuelto', 'postmortem' => 'Postmortem'];

    public const SEVERITY_LABELS = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica'];

    private const FLOW = ['investigating' => ['identified', 'mitigating', 'monitoring', 'resolved'], 'identified' => ['mitigating', 'monitoring', 'resolved'], 'mitigating' => ['monitoring', 'resolved'],
        'monitoring' => ['mitigating', 'resolved'], 'resolved' => ['postmortem', 'monitoring'], 'postmortem' => []];

    public function __construct(private PlatformAuditService $audit, private NotificationTemplateService $templates, private TenantDatabaseContext $tenancy) {}

    /**
     * @param  list<string>  $services
     * @param  list<int>  $organizationIds  explicit affected organizations (empty = calculated from the services)
     */
    public function create(User $actor, string $title, string $description, string $severity, array $services, string $audience, array $organizationIds, ?string $startedAt): int
    {
        if (! isset(self::SEVERITY_LABELS[$severity]) || ! in_array($audience, ['all', 'affected', 'plan', 'none'], true)) {
            throw ValidationException::withMessages(['severity' => 'Severidad o audiencia inválidas.']);
        }
        if (array_diff($services, array_keys(PlatformHealthService::SERVICES)) !== []) {
            throw ValidationException::withMessages(['affected_services' => 'Servicio desconocido.']);
        }

        return DB::transaction(function () use ($actor, $title, $description, $severity, $services, $audience, $organizationIds, $startedAt): int {
            $id = DB::table('system_incidents')->insertGetId(['public_id' => (string) Str::uuid(), 'code' => sprintf('INC-%04d', (int) DB::table('system_incidents')->max('id') + 1), 'title' => $title,
                'description' => $description, 'severity' => $severity, 'affected_services' => json_encode($services, JSON_THROW_ON_ERROR), 'audience' => $audience, 'status' => 'investigating',
                'owner_id' => $actor->id, 'started_at' => $startedAt === null ? now() : Carbon::parse($startedAt), 'detected_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $affected = $organizationIds !== [] ? $organizationIds : $this->affectedOrganizations($services);
            foreach (array_unique($affected) as $organizationId) {
                DB::table('system_incident_organizations')->insertOrIgnore(['system_incident_id' => $id, 'organization_id' => $organizationId, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('system_incident_updates')->insert(['system_incident_id' => $id, 'status' => 'investigating', 'message' => $description, 'published' => false, 'author_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($actor, 'incident.created', 'system_incidents', $id, null, ['title' => $title, 'severity' => $severity, 'services' => $services, 'affected' => count($affected)]);

            return $id;
        });
    }

    /**
     * Every active organization depends on the core services; optional ones (Luzma, payments, FEL) affect only the
     * organizations that use them.
     *
     * @param  list<string>  $services
     * @return list<int>
     */
    public function affectedOrganizations(array $services): array
    {
        $active = DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereIn('status', ['active', 'trial', 'past_due', 'grace_period']);
        if (array_intersect($services, ['app', 'database', 'queues', 'storage', 'scheduler', 'cache', 'redis']) !== []) {
            return array_values(array_map('intval', $active->pluck('id')->all()));
        }
        $ids = [];
        if (array_intersect($services, ['luzma_gateway', 'ollama']) !== []) {
            $ids = [...$ids, ...DB::table('luzma_messages')->where('created_at', '>=', now()->subDays(30))->distinct()->pluck('organization_id')->all()];
        }
        if (in_array('fel', $services, true)) {
            $ids = [...$ids, ...DB::table('fel_documents')->where('created_at', '>=', now()->subDays(60))->distinct()->pluck('organization_id')->all()];
        }
        if (in_array('payments', $services, true)) {
            $ids = [...$ids, ...DB::table('subscriptions')->where('provider', 'recurrente')->whereIn('status', ['active', 'past_due', 'grace_period'])->pluck('organization_id')->all()];
        }
        if (in_array('mail', $services, true)) {
            $ids = [...$ids, ...$active->pluck('id')->all()];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Timeline update; when published it is shown to the audience in the app and sent by e-mail. */
    public function update(User $actor, int $incidentId, string $status, string $message, bool $publish): void
    {
        DB::transaction(function () use ($actor, $incidentId, $status, $message, $publish): void {
            $incident = DB::table('system_incidents')->where('id', $incidentId)->lockForUpdate()->first() ?? abort(404);
            if ($status !== $incident->status && ! in_array($status, self::FLOW[$incident->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'No se puede pasar de «'.self::STATUS_LABELS[$incident->status].'» a «'.(self::STATUS_LABELS[$status] ?? $status).'».']);
            }
            DB::table('system_incidents')->where('id', $incidentId)->update(['status' => $status, 'resolved_at' => in_array($status, ['resolved', 'postmortem'], true) ? ($incident->resolved_at ?? now()) : null, 'updated_at' => now()]);
            DB::table('system_incident_updates')->insert(['system_incident_id' => $incidentId, 'status' => $status, 'message' => $message, 'published' => $publish, 'author_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($publish && $incident->audience !== 'none') {
                foreach ($this->audience($incident) as $organizationId) {
                    $this->templates->notifyOrganization($organizationId, 'incident_notification', ['title' => (string) $incident->title, 'message' => $message, 'status' => self::STATUS_LABELS[$status]]);
                }
            }
            $this->audit->record($actor, $status === 'resolved' && $incident->status !== 'resolved' ? 'incident.resolved' : 'incident.updated', 'system_incidents', $incidentId,
                ['status' => $incident->status], ['status' => $status, 'published' => $publish], $message);
        });
    }

    public function postmortem(User $actor, int $incidentId, string $postmortem): void
    {
        $incident = DB::table('system_incidents')->where('id', $incidentId)->first() ?? abort(404);
        if (! in_array($incident->status, ['resolved', 'postmortem'], true)) {
            throw ValidationException::withMessages(['postmortem' => 'El postmortem se registra cuando el incidente está resuelto.']);
        }
        DB::table('system_incidents')->where('id', $incidentId)->update(['status' => 'postmortem', 'postmortem' => $postmortem, 'updated_at' => now()]);
        $this->audit->record($actor, 'incident.postmortem', 'system_incidents', $incidentId, ['status' => $incident->status], ['status' => 'postmortem']);
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $activeOnly = false): array
    {
        return array_values(DB::table('system_incidents')->when($activeOnly, fn ($q) => $q->whereNotIn('status', ['resolved', 'postmortem']))
            ->orderByRaw("CASE WHEN status IN ('resolved', 'postmortem') THEN 1 ELSE 0 END")->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('id')->limit(200)->get()->map(fn (stdClass $row): array => $this->row($row))->all());
    }

    /** @return array<string, mixed> */
    public function detail(int $incidentId): array
    {
        $incident = DB::table('system_incidents')->where('id', $incidentId)->first() ?? abort(404);
        $names = DB::table('users')->pluck('name', 'id');

        return [...$this->row($incident), 'description' => (string) $incident->description, 'postmortem' => $incident->postmortem,
            'organizations' => DB::table('system_incident_organizations')->join('organizations', 'organizations.id', '=', 'system_incident_organizations.organization_id')
                ->where('system_incident_id', $incidentId)->orderBy('organizations.name')->get(['organizations.id', 'organizations.name'])->all(),
            'updates' => DB::table('system_incident_updates')->where('system_incident_id', $incidentId)->orderBy('id')->get()
                ->map(fn (stdClass $update): array => ['status' => (string) $update->status, 'status_label' => self::STATUS_LABELS[$update->status] ?? $update->status, 'message' => (string) $update->message,
                    'published' => (bool) $update->published, 'author' => $names[$update->author_id] ?? null, 'at' => Carbon::parse($update->created_at)->toIso8601String()])->all()];
    }

    /**
     * Incidents currently published to an organization (banner inside the tenant application).
     *
     * @return list<array{code: string, title: string, severity: string, status: string, message: string, at: string}>
     */
    public function forTenant(int $organizationId): array
    {
        return $this->tenancy->runAsSystem(function () use ($organizationId): array {
            $incidents = DB::table('system_incidents')->whereNotIn('status', ['resolved', 'postmortem'])->where('audience', '!=', 'none')
                ->where(fn ($q) => $q->where('audience', 'all')->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('system_incident_organizations')
                    ->whereColumn('system_incident_organizations.system_incident_id', 'system_incidents.id')->where('organization_id', $organizationId)))->get();
            $rows = [];
            foreach ($incidents as $incident) {
                $update = DB::table('system_incident_updates')->where('system_incident_id', $incident->id)->where('published', true)->orderByDesc('id')->first();
                if ($update !== null) {
                    $rows[] = ['code' => (string) $incident->code, 'title' => (string) $incident->title, 'severity' => (string) $incident->severity, 'status' => self::STATUS_LABELS[$incident->status],
                        'message' => (string) $update->message, 'at' => Carbon::parse($update->created_at)->toIso8601String()];
                }
            }

            return $rows;
        });
    }

    /** @return array<string, mixed> */
    private function row(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'code' => (string) $row->code, 'title' => (string) $row->title, 'severity' => (string) $row->severity, 'severity_label' => self::SEVERITY_LABELS[$row->severity],
            'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status], 'audience' => (string) $row->audience,
            'affected_services' => (array) json_decode((string) $row->affected_services, true), 'affected_organizations' => DB::table('system_incident_organizations')->where('system_incident_id', $row->id)->count(),
            'owner' => $row->owner_id === null ? null : DB::table('users')->where('id', $row->owner_id)->value('name'),
            'started_at' => Carbon::parse($row->started_at)->toIso8601String(), 'detected_at' => Carbon::parse($row->detected_at)->toIso8601String(),
            'resolved_at' => $row->resolved_at === null ? null : Carbon::parse($row->resolved_at)->toIso8601String()];
    }

    /** @return list<int> */
    private function audience(stdClass $incident): array
    {
        if ($incident->audience === 'all') {
            return array_values(array_map('intval', DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->pluck('id')->all()));
        }

        return array_values(array_map('intval', DB::table('system_incident_organizations')->where('system_incident_id', $incident->id)->pluck('organization_id')->all()));
    }
}
