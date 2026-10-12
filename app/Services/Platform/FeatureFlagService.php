<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Feature flags for gradual releases. Resolution (most specific wins): user → organization → beta group → plan →
 * global value. Every change records flag, old and new value, target, who and why.
 */
class FeatureFlagService
{
    public const TARGETS = ['plan' => 'Plan', 'organization' => 'Organización', 'beta_group' => 'Grupo beta', 'user' => 'Usuario'];

    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuditService $audit) {}

    public function enabled(string $key, ?int $organizationId = null, ?int $userId = null): bool
    {
        return $this->tenancy->runAsSystem(function () use ($key, $organizationId, $userId): bool {
            $flag = DB::table('feature_flags')->where('key', $key)->where('status', 'active')->first();
            if ($flag === null) {
                return false;
            }
            $targets = DB::table('feature_flag_targets')->where('feature_flag_id', $flag->id)->get();
            $match = fn (string $type, ?string $id): ?stdClass => $id === null ? null : $targets->first(fn (stdClass $t): bool => $t->target_type === $type && $t->target_id === $id);
            $planId = $organizationId === null ? null : DB::table('subscriptions')->join('subscription_items', 'subscription_items.subscription_id', '=', 'subscriptions.id')
                ->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->where('subscriptions.organization_id', $organizationId)
                ->whereIn('subscriptions.status', ['active', 'trialing', 'past_due', 'grace_period'])->orderByDesc('subscriptions.id')->value('plan_prices.plan_id');
            $beta = $organizationId !== null && (bool) DB::table('organizations')->where('id', $organizationId)->value('beta_program');
            $target = $match('user', $userId === null ? null : (string) $userId)
                ?? $match('organization', $organizationId === null ? null : (string) $organizationId)
                ?? ($beta ? $match('beta_group', 'beta') : null)
                ?? $match('plan', $planId === null ? null : (string) $planId);

            return $target !== null ? (bool) $target->enabled : (bool) $flag->enabled_globally;
        });
    }

    /** @return list<string> */
    public function enabledFor(?int $organizationId, ?int $userId): array
    {
        $keys = $this->tenancy->runAsSystem(fn () => DB::table('feature_flags')->where('status', 'active')->pluck('key')->all());

        return array_values(array_filter($keys, fn (string $key): bool => $this->enabled($key, $organizationId, $userId)));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $names = ['plan' => DB::table('plans')->pluck('name', 'id'), 'organization' => DB::table('organizations')->pluck('name', 'id'), 'user' => DB::table('users')->pluck('email', 'id')];

        return array_values(DB::table('feature_flags')->orderBy('key')->get()->map(fn (stdClass $flag): array => [
            'id' => (int) $flag->id, 'key' => (string) $flag->key, 'name' => (string) $flag->name, 'description' => $flag->description, 'enabled_globally' => (bool) $flag->enabled_globally,
            'status' => (string) $flag->status,
            'targets' => array_values(DB::table('feature_flag_targets')->where('feature_flag_id', $flag->id)->orderBy('target_type')->get()->map(fn (stdClass $target): array => [
                'id' => (int) $target->id, 'type' => (string) $target->target_type, 'type_label' => self::TARGETS[$target->target_type] ?? $target->target_type, 'target_id' => (string) $target->target_id,
                'label' => $target->target_type === 'beta_group' ? 'Organizaciones del programa beta' : (string) ($names[$target->target_type][(int) $target->target_id] ?? $target->target_id),
                'enabled' => (bool) $target->enabled, 'reason' => $target->reason])->all()),
        ])->all());
    }

    public function create(User $actor, string $key, string $name, ?string $description, string $reason): int
    {
        if (preg_match('/^[a-z0-9_.]{3,80}$/', $key) !== 1) {
            throw ValidationException::withMessages(['key' => 'Usa minúsculas, números, puntos o guiones bajos.']);
        }
        if (DB::table('feature_flags')->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => 'Ya existe un flag con esa clave.']);
        }
        $id = DB::table('feature_flags')->insertGetId(['key' => $key, 'name' => $name, 'description' => $description, 'enabled_globally' => false, 'status' => 'active',
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($actor, 'feature_flag.created', 'feature_flags', $key, null, ['enabled_globally' => false], $reason);

        return $id;
    }

    /** Global switch: a sensitive change (re-authenticated by the caller). */
    public function setGlobal(User $actor, int $flagId, bool $enabled, string $reason): void
    {
        $flag = DB::table('feature_flags')->where('id', $flagId)->first() ?? abort(404);
        DB::table('feature_flags')->where('id', $flagId)->update(['enabled_globally' => $enabled, 'updated_at' => now()]);
        $this->audit->record($actor, 'feature_flag.changed', 'feature_flags', (string) $flag->key, ['target' => 'global', 'value' => (bool) $flag->enabled_globally],
            ['target' => 'global', 'value' => $enabled], $reason, null, ['affected_organizations' => $this->affectedCount()], true);
    }

    public function setTarget(User $actor, int $flagId, string $type, string $targetId, bool $enabled, string $reason): void
    {
        $flag = DB::table('feature_flags')->where('id', $flagId)->first() ?? abort(404);
        if (! isset(self::TARGETS[$type])) {
            throw ValidationException::withMessages(['target_type' => 'Tipo de destino inválido.']);
        }
        $targetId = $type === 'beta_group' ? 'beta' : $targetId;
        $exists = match ($type) {
            'plan' => DB::table('plans')->where('id', (int) $targetId)->exists(),
            'organization' => DB::table('organizations')->where('id', (int) $targetId)->exists(),
            'user' => DB::table('users')->where('id', (int) $targetId)->exists(),
            default => true,
        };
        if (! $exists) {
            throw ValidationException::withMessages(['target_id' => 'El destino no existe.']);
        }
        $previous = DB::table('feature_flag_targets')->where('feature_flag_id', $flagId)->where('target_type', $type)->where('target_id', $targetId)->first();
        DB::table('feature_flag_targets')->updateOrInsert(['feature_flag_id' => $flagId, 'target_type' => $type, 'target_id' => $targetId],
            ['enabled' => $enabled, 'reason' => $reason, 'created_by' => $actor->id, 'updated_at' => now(), 'created_at' => $previous->created_at ?? now()]);
        $this->audit->record($actor, 'feature_flag.changed', 'feature_flags', (string) $flag->key, ['target' => $type.':'.$targetId, 'value' => $previous === null ? null : (bool) $previous->enabled],
            ['target' => $type.':'.$targetId, 'value' => $enabled], $reason, $type === 'organization' ? (int) $targetId : null);
    }

    public function removeTarget(User $actor, int $targetId, string $reason): void
    {
        $target = DB::table('feature_flag_targets')->where('id', $targetId)->first() ?? abort(404);
        $flag = DB::table('feature_flags')->where('id', $target->feature_flag_id)->first();
        DB::table('feature_flag_targets')->where('id', $targetId)->delete();
        $this->audit->record($actor, 'feature_flag.changed', 'feature_flags', (string) ($flag->key ?? ''), ['target' => $target->target_type.':'.$target->target_id, 'value' => (bool) $target->enabled],
            ['target' => $target->target_type.':'.$target->target_id, 'value' => 'removed'], $reason);
    }

    /** Organizations that would be affected by a global change (preview). */
    public function affectedCount(): int
    {
        return DB::table('organizations')->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->count();
    }
}
