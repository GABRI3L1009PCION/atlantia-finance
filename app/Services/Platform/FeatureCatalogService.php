<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Global catalog of features (modules and capabilities), their release stage and dependencies, and the temporary
 * per-organization overrides. Dependencies are validated here (FeatureDependencyService) so a plan or an override can
 * never enable a feature whose requirements are missing, nor two features that conflict.
 */
class FeatureCatalogService
{
    public const STAGES = ['internal' => 'Interno', 'alpha' => 'Alfa', 'beta' => 'Beta', 'general_availability' => 'Disponible', 'deprecated' => 'Obsoleto'];

    public function __construct(private PlatformAuditService $audit, private EntitlementResolverService $entitlements) {}

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        $plans = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')->where('plan_versions.status', 'published')->orderBy('plans.display_order')
            ->get(['plans.name', 'plan_versions.entitlements']);
        $overrides = DB::table('entitlement_overrides')->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('feature_code')->selectRaw('feature_code, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'feature_code');

        return array_values(DB::table('features')->orderBy('module')->orderBy('name')->get()->map(fn (stdClass $feature): array => [
            'id' => (int) $feature->id, 'key' => (string) $feature->code, 'name' => (string) $feature->name, 'module' => $feature->module, 'description' => $feature->description,
            'status' => (string) $feature->status, 'release_stage' => (string) $feature->release_stage, 'stage_label' => self::STAGES[$feature->release_stage] ?? $feature->release_stage,
            'requires' => (array) json_decode((string) ($feature->requires ?? '[]'), true), 'conflicts_with' => (array) json_decode((string) ($feature->conflicts_with ?? '[]'), true),
            'plans' => array_values($plans->filter(fn (stdClass $plan): bool => in_array($feature->code, (array) json_decode((string) $plan->entitlements, true), true))->pluck('name')->all()),
            'overrides' => (int) ($overrides[$feature->code] ?? 0), 'created_at' => Carbon::parse($feature->created_at)->toIso8601String(),
        ])->all());
    }

    /**
     * @param  array{name: string, description?: string|null, module?: string|null, status: string, release_stage: string, requires: list<string>, conflicts_with: list<string>}  $data
     */
    public function update(User $actor, string $key, array $data): void
    {
        $feature = DB::table('features')->where('code', $key)->first() ?? abort(404);
        $known = DB::table('features')->pluck('code')->all();
        foreach (['requires', 'conflicts_with'] as $field) {
            if (array_diff($data[$field], $known) !== [] || in_array($key, $data[$field], true)) {
                throw ValidationException::withMessages([$field => 'Elige funciones existentes distintas de la misma.']);
            }
        }
        if (array_intersect($data['requires'], $data['conflicts_with']) !== []) {
            throw ValidationException::withMessages(['conflicts_with' => 'Una función no puede requerir y a la vez estar en conflicto con otra.']);
        }
        if ($this->createsCycle($key, $data['requires'])) {
            throw ValidationException::withMessages(['requires' => 'Esa dependencia crea un ciclo entre funciones.']);
        }
        if (! isset(self::STAGES[$data['release_stage']]) || ! in_array($data['status'], ['active', 'disabled'], true)) {
            throw ValidationException::withMessages(['release_stage' => 'Etapa o estado inválidos.']);
        }
        $after = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'module' => $data['module'] ?? null, 'status' => $data['status'], 'release_stage' => $data['release_stage'],
            'requires' => json_encode($data['requires'], JSON_THROW_ON_ERROR), 'conflicts_with' => json_encode($data['conflicts_with'], JSON_THROW_ON_ERROR)];
        DB::table('features')->where('id', $feature->id)->update([...$after, 'updated_at' => now()]);
        $this->audit->record($actor, 'feature.updated', 'features', $key, (array) $feature, $after);
    }

    /**
     * Temporary exception for one organization (does not change the global plan).
     */
    public function createOverride(User $actor, int $organizationId, string $feature, bool $value, string $reason, ?string $expiresAt): int
    {
        $definition = DB::table('features')->where('code', $feature)->first() ?? throw ValidationException::withMessages(['feature_code' => 'Función desconocida.']);
        DB::table('organizations')->where('id', $organizationId)->firstOrFail();
        if ($value) {
            $effective = $this->entitlements->enabled($organizationId);
            $missing = array_values(array_diff((array) json_decode((string) ($definition->requires ?? '[]'), true), $effective));
            if ($missing !== []) {
                throw ValidationException::withMessages(['feature_code' => 'Primero habilita lo que esta función requiere: '.implode(', ', $missing).'.']);
            }
            $conflicts = array_values(array_intersect((array) json_decode((string) ($definition->conflicts_with ?? '[]'), true), $effective));
            if ($conflicts !== []) {
                throw ValidationException::withMessages(['feature_code' => 'Está en conflicto con: '.implode(', ', $conflicts).'.']);
            }
        }
        if ($expiresAt !== null && Carbon::parse($expiresAt)->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'La fecha de vencimiento debe ser futura.']);
        }
        DB::table('entitlement_overrides')->where('organization_id', $organizationId)->where('feature_code', $feature)->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
        $id = DB::table('entitlement_overrides')->insertGetId(['organization_id' => $organizationId, 'feature_code' => $feature, 'value' => $value, 'reason' => $reason,
            'granted_by' => $actor->id, 'valid_from' => now(), 'expires_at' => $expiresAt === null ? null : Carbon::parse($expiresAt)->endOfDay(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->entitlements->forget($organizationId);
        $this->audit->record($actor, 'entitlement.override.created', 'entitlement_overrides', $id, null, ['feature' => $feature, 'value' => $value, 'expires_at' => $expiresAt], $reason, $organizationId, [], true);

        return $id;
    }

    public function revokeOverride(User $actor, int $overrideId, string $reason): void
    {
        $override = DB::table('entitlement_overrides')->where('id', $overrideId)->first() ?? abort(404);
        if ($override->status !== 'active') {
            throw ValidationException::withMessages(['override' => 'La excepción ya no está activa.']);
        }
        DB::table('entitlement_overrides')->where('id', $overrideId)->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
        $this->entitlements->forget((int) $override->organization_id);
        $this->audit->record($actor, 'entitlement.override.revoked', 'entitlement_overrides', $overrideId, ['feature' => $override->feature_code, 'value' => (bool) $override->value], null, $reason, (int) $override->organization_id);
    }

    /** @return list<array<string, mixed>> */
    public function overrides(?int $organizationId = null): array
    {
        return array_values(DB::table('entitlement_overrides')->join('organizations', 'organizations.id', '=', 'entitlement_overrides.organization_id')
            ->leftJoin('users', 'users.id', '=', 'entitlement_overrides.granted_by')
            ->when($organizationId, fn ($q) => $q->where('entitlement_overrides.organization_id', $organizationId))
            ->orderByDesc('entitlement_overrides.id')->limit(200)->get(['entitlement_overrides.*', 'organizations.name as organization', 'users.name as granted_by_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'organization_id' => (int) $row->organization_id, 'organization' => (string) $row->organization, 'feature' => (string) $row->feature_code,
                'value' => (bool) $row->value, 'reason' => (string) $row->reason, 'granted_by' => $row->granted_by_name, 'valid_from' => Carbon::parse($row->valid_from)->toIso8601String(),
                'expires_at' => $row->expires_at === null ? null : Carbon::parse($row->expires_at)->toIso8601String(),
                'status' => $row->status === 'active' && $row->expires_at !== null && Carbon::parse($row->expires_at)->isPast() ? 'expired' : (string) $row->status])->all());
    }

    /** @param list<string> $requires */
    private function createsCycle(string $key, array $requires): bool
    {
        $graph = DB::table('features')->pluck('requires', 'code')->map(fn ($json): array => (array) json_decode((string) ($json ?? '[]'), true))->all();
        $graph[$key] = $requires;
        $stack = $requires;
        $seen = [];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === $key) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $stack = [...$stack, ...($graph[$current] ?? [])];
        }

        return false;
    }
}
