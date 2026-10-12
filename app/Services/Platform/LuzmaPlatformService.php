<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\Luzma\LuzmaTool;
use App\Services\Luzma\LuzmaToolRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Luzma as a platform service: gateway/model health, usage metrics per tenant and tool administration. Only counts,
 * latency, errors and sizes are exposed — never the content of tenant conversations.
 */
class LuzmaPlatformService
{
    public const TOOL_STATES = ['enabled' => 'Habilitada', 'disabled' => 'Deshabilitada', 'beta' => 'Beta', 'deprecated' => 'Obsoleta'];

    public const RISK_LABELS = [LuzmaTool::READ_ONLY => 'Solo lectura', LuzmaTool::PREPARE => 'Prepara', LuzmaTool::LOW_RISK_WRITE => 'Escritura de bajo riesgo',
        LuzmaTool::SENSITIVE_WRITE => 'Escritura sensible', LuzmaTool::CRITICAL => 'Crítica'];

    public function __construct(private LuzmaModelProvider $provider, private PlatformAuditService $audit) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $health = $this->provider->health();
        $today = DB::table('luzma_messages')->where('created_at', '>=', now()->startOfDay())->where('role', 'assistant')
            ->selectRaw("CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE status = 'ok') AS BIGINT) as ok, CAST(COUNT(*) FILTER (WHERE status <> 'ok') AS BIGINT) as errors, AVG(latency_ms) as latency")->first();
        $uptime = DB::table('service_health_checks')->where('service', 'luzma_gateway')->where('checked_at', '>=', now()->subDay())
            ->selectRaw("CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE status = 'healthy') AS BIGINT) as healthy")->first();
        $gatewayData = (array) json_decode((string) (DB::table('service_health_checks')->where('service', 'luzma_gateway')->orderByDesc('id')->value('data') ?? '{}'), true);

        return [
            'enabled' => (bool) config('luzma.enabled'), 'gateway' => (bool) $health['gateway'], 'model_loaded' => (bool) $health['model_loaded'],
            'status' => ! (bool) config('luzma.enabled') ? 'maintenance' : ($health['available'] ? 'healthy' : ($health['gateway'] ? 'degraded' : 'down')),
            'model' => $health['model'], 'latency_ms' => $health['latency_ms'], 'detail' => $health['detail'], 'gateway_url' => (string) config('luzma.gateway_url'),
            'gpu' => $gatewayData['gpu'] ?? null, 'vram' => $gatewayData['vram'] ?? null,
            'requests_today' => (int) ($today->total ?? 0), 'successful_today' => (int) ($today->ok ?? 0), 'errors_today' => (int) ($today->errors ?? 0),
            'average_latency_ms' => $today->latency === null ? null : (int) round((float) $today->latency),
            'uptime_24h' => ($uptime->total ?? 0) > 0 ? round((int) $uptime->healthy * 100 / (int) $uptime->total, 2) : null,
            'queue' => DB::table('jobs')->where('payload', 'like', '%LuzmaMaintenanceJob%')->count(),
            'month_requests' => DB::table('luzma_messages')->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    /**
     * Usage per organization this month: requests, successful/failed, latency, context size and limit. No content.
     *
     * @return list<array<string, mixed>>
     */
    public function usageByTenant(EntitlementResolverService $entitlements): array
    {
        $month = now()->startOfMonth();
        $questions = DB::table('luzma_messages')->where('role', 'user')->where('created_at', '>=', $month)->groupBy('organization_id')->selectRaw('organization_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'organization_id');

        return array_values(DB::table('luzma_messages')->join('organizations', 'organizations.id', '=', 'luzma_messages.organization_id')->where('luzma_messages.role', 'assistant')
            ->where('luzma_messages.created_at', '>=', $month)->groupBy('luzma_messages.organization_id', 'organizations.name')
            ->selectRaw("luzma_messages.organization_id, organizations.name, CAST(COUNT(*) AS BIGINT) as answers, CAST(COUNT(*) FILTER (WHERE luzma_messages.status = 'ok') AS BIGINT) as ok,
                CAST(COUNT(*) FILTER (WHERE luzma_messages.status <> 'ok') AS BIGINT) as failed, AVG(luzma_messages.latency_ms) as latency, AVG(octet_length(COALESCE(luzma_messages.context_snapshot::text, ''))) as context")
            ->orderByDesc('answers')->get()->map(function (stdClass $row) use ($questions, $entitlements): array {
                $limit = $entitlements->limit((int) $row->organization_id, 'ai_queries');
                $requests = (int) ($questions[$row->organization_id] ?? 0);

                return ['organization_id' => (int) $row->organization_id, 'tenant' => (string) $row->name, 'requests' => $requests, 'successful' => (int) $row->ok, 'failed' => (int) $row->failed,
                    'latency_ms' => $row->latency === null ? null : (int) round((float) $row->latency), 'context_bytes' => $row->context === null ? null : (int) round((float) $row->context),
                    'limit' => $limit, 'percent' => $limit === null || $limit === 0 ? null : (int) floor($requests * 100 / $limit)];
            })->all());
    }

    /** @return list<array<string, mixed>> */
    public function tools(LuzmaToolRegistry $registry): array
    {
        $states = DB::table('luzma_tool_states')->get()->keyBy('tool_key');
        $since = now()->subDays(30);
        $usage = DB::table('luzma_messages')->where('created_at', '>=', $since)->whereNotNull('tool_calls')
            ->selectRaw("tool->>'tool' as tool, CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE (tool->>'ok')::boolean = false) AS BIGINT) as failed, MAX(created_at) FILTER (WHERE (tool->>'ok')::boolean = false) as last_error")
            ->crossJoin(DB::raw("jsonb_array_elements(CASE WHEN jsonb_typeof(tool_calls::jsonb) = 'array' THEN tool_calls::jsonb ELSE '[]'::jsonb END) as tool"))
            ->groupByRaw("tool->>'tool'")->get()->keyBy('tool');

        return array_values(array_map(function (LuzmaTool $tool) use ($states, $usage): array {
            $state = $states[$tool->name] ?? null;
            $stats = $usage[$tool->name] ?? null;

            return ['key' => $tool->name, 'description' => $tool->description, 'module' => $this->module($tool->name), 'risk' => $tool->level, 'risk_label' => self::RISK_LABELS[$tool->level] ?? $tool->level,
                'status' => (string) ($state->status ?? 'enabled'), 'status_label' => self::TOOL_STATES[$state->status ?? 'enabled'], 'reason' => $state->reason ?? null,
                'usage_30d' => (int) ($stats->total ?? 0), 'failures_30d' => (int) ($stats->failed ?? 0), 'last_error' => $stats?->last_error === null ? null : Carbon::parse($stats->last_error)->toIso8601String(),
                'permissions' => $tool->permissions];
        }, $registry->all()));
    }

    public function setToolStatus(User $actor, LuzmaToolRegistry $registry, string $tool, string $status, string $reason): void
    {
        if ($registry->find($tool) === null) {
            throw ValidationException::withMessages(['tool' => 'Herramienta desconocida.']);
        }
        if (! isset(self::TOOL_STATES[$status])) {
            throw ValidationException::withMessages(['status' => 'Estado inválido.']);
        }
        $previous = DB::table('luzma_tool_states')->where('tool_key', $tool)->value('status') ?? 'enabled';
        DB::table('luzma_tool_states')->updateOrInsert(['tool_key' => $tool], ['status' => $status, 'reason' => $reason, 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record($actor, 'luzma.tool.changed', 'luzma_tool_states', $tool, ['status' => $previous], ['status' => $status], $reason);
    }

    private function module(string $tool): string
    {
        return match (true) {
            str_contains($tool, 'payable') || str_contains($tool, 'supplier') => 'Cuentas por pagar',
            str_contains($tool, 'receivable') || str_contains($tool, 'collection') || str_contains($tool, 'customer') => 'Cuentas por cobrar',
            str_contains($tool, 'payroll') => 'Nómina',
            str_contains($tool, 'treasury') || str_contains($tool, 'bank') || str_contains($tool, 'cash') => 'Tesorería',
            str_contains($tool, 'tax') || str_contains($tool, 'fiscal') || str_contains($tool, 'vat') => 'Fiscal',
            str_contains($tool, 'audit') => 'Auditoría',
            str_contains($tool, 'normative') => 'Biblioteca normativa',
            str_contains($tool, 'client') => 'Portal del cliente',
            str_contains($tool, 'reminder') => 'Recordatorios',
            default => 'General',
        };
    }
}
