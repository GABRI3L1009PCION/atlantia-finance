<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Plans and their immutable versions. Editing a plan creates a new draft version; publishing it updates what is sold
 * from then on (prices, entitlements and limits used by checkout) while every existing subscription keeps the version
 * it bought until it is explicitly moved. All plans run on the same engine: they differ only in entitlements and limits.
 */
class PlanService
{
    public const METRICS = [
        'offices' => ['label' => 'Oficinas', 'unit' => 'offices'], 'users' => ['label' => 'Usuarios', 'unit' => 'users'], 'active_cases' => ['label' => 'Empresas cliente', 'unit' => 'cases'],
        'storage_bytes' => ['label' => 'Almacenamiento', 'unit' => 'bytes'], 'ai_queries' => ['label' => 'Consultas de Luzma al mes', 'unit' => 'queries/month'],
        'api_requests' => ['label' => 'Solicitudes de API al mes', 'unit' => 'requests/month'],
    ];

    public function __construct(private PlatformAuditService $audit) {}

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        $subscribers = DB::table('subscription_items')->join('subscriptions', 'subscriptions.id', '=', 'subscription_items.subscription_id')
            ->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->where('item_type', 'base_plan')
            ->whereIn('subscriptions.status', ['active', 'trialing', 'past_due', 'grace_period'])->groupBy('plan_prices.plan_id')
            ->selectRaw('plan_prices.plan_id, CAST(COUNT(DISTINCT subscriptions.id) AS BIGINT) as total')->pluck('total', 'plan_id');

        return array_values(DB::table('plans')->orderBy('display_order')->get()->map(fn (stdClass $plan): array => [
            'id' => (int) $plan->id, 'code' => (string) $plan->code, 'name' => (string) $plan->name, 'description' => $plan->description, 'is_active' => (bool) $plan->is_active,
            'display_order' => (int) $plan->display_order, 'subscribers' => (int) ($subscribers[$plan->id] ?? 0),
            'presentation' => ['tagline' => $plan->tagline, 'highlights' => (array) json_decode((string) ($plan->highlights ?? '[]'), true), 'is_recommended' => (bool) $plan->is_recommended,
                'price_prefix' => $plan->price_prefix, 'cta_label' => $plan->cta_label],
            'versions' => array_values(DB::table('plan_versions')->where('plan_id', $plan->id)->orderByDesc('version')->get()->map(fn (stdClass $version): array => $this->version($version))->all()),
        ])->all());
    }

    /** @return array<string, mixed> */
    public function version(stdClass $version): array
    {
        return ['id' => (int) $version->id, 'version' => (int) $version->version, 'name' => (string) $version->name, 'description' => $version->description,
            'currency' => (string) $version->currency_code, 'monthly_price' => (string) $version->monthly_price, 'annual_price' => (string) $version->annual_price,
            'entitlements' => (array) json_decode((string) $version->entitlements, true), 'limits' => (array) json_decode((string) $version->limits, true),
            'status' => (string) $version->status, 'change_note' => $version->change_note, 'published_at' => $version->published_at === null ? null : Carbon::parse($version->published_at)->toIso8601String(),
            'subscriptions' => DB::table('subscriptions')->where('plan_version_id', $version->id)->whereIn('status', ['active', 'trialing', 'past_due', 'grace_period'])->count()];
    }

    /**
     * @param  array{code: string, name: string, description?: string|null, monthly_price: string|float|int, annual_price: string|float|int, entitlements: list<string>, limits: array<string, array{value: int|null, warning?: int, high?: int, enforcement?: string}>}  $data
     */
    public function createPlan(User $actor, array $data): int
    {
        if (DB::table('plans')->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe un plan con ese código.']);
        }

        return DB::transaction(function () use ($actor, $data): int {
            $planId = DB::table('plans')->insertGetId(['code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => false,
                'display_order' => (int) DB::table('plans')->max('display_order') + 1, 'created_at' => now(), 'updated_at' => now()]);
            $versionId = $this->insertVersion($actor, $planId, 1, $data, 'Plan creado desde el Control Center.');
            $this->audit->record($actor, 'plan.created', 'plans', $planId, null, ['code' => $data['code'], 'name' => $data['name'], 'version_id' => $versionId]);

            return $planId;
        });
    }

    /**
     * A new draft version based on the data given (never edits a published version).
     *
     * @param  array{name: string, description?: string|null, monthly_price: string|float|int, annual_price: string|float|int, entitlements: list<string>, limits: array<string, array{value: int|null, warning?: int, high?: int, enforcement?: string}>}  $data
     */
    public function draftVersion(User $actor, int $planId, array $data, string $note): int
    {
        DB::table('plans')->where('id', $planId)->firstOrFail();
        if (DB::table('plan_versions')->where('plan_id', $planId)->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['version' => 'Ya hay una versión en borrador de este plan: publícala o descártala primero.']);
        }
        $version = (int) DB::table('plan_versions')->where('plan_id', $planId)->max('version') + 1;
        $id = $this->insertVersion($actor, $planId, $version, $data, $note);
        $this->audit->record($actor, 'plan.version_drafted', 'plan_versions', $id, null, ['plan_id' => $planId, 'version' => $version], $note);

        return $id;
    }

    public function discardDraft(User $actor, int $versionId): void
    {
        $version = DB::table('plan_versions')->where('id', $versionId)->first() ?? abort(404);
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Solo se descarta una versión en borrador.']);
        }
        DB::table('plan_versions')->where('id', $versionId)->delete();
        $this->audit->record($actor, 'plan.version_discarded', 'plan_versions', $versionId, ['version' => (int) $version->version], null);
    }

    /**
     * Publishes a draft: it becomes what is sold (prices, features and limits of checkout). Existing subscriptions keep
     * their version.
     */
    public function publish(User $actor, int $versionId, string $reason): void
    {
        DB::transaction(function () use ($actor, $versionId, $reason): void {
            $version = DB::table('plan_versions')->where('id', $versionId)->lockForUpdate()->first() ?? abort(404);
            if ($version->status !== 'draft') {
                throw ValidationException::withMessages(['version' => 'Solo se publica una versión en borrador.']);
            }
            $previous = DB::table('plan_versions')->where('plan_id', $version->plan_id)->where('status', 'published')->orderByDesc('version')->first();
            DB::table('plan_versions')->where('plan_id', $version->plan_id)->where('status', 'published')->update(['status' => 'retired', 'retired_at' => now(), 'updated_at' => now()]);
            DB::table('plan_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => now(), 'updated_at' => now()]);
            $this->project($version);
            $this->audit->record($actor, 'plan.updated', 'plans', (int) $version->plan_id, $previous === null ? null : $this->version($previous), $this->version($version), $reason, null, ['published_version' => (int) $version->version], true);
        });
    }

    /** Stops or resumes selling a plan; existing subscriptions are not touched. */
    /**
     * Sales-page presentation of a plan. Only one plan can be the recommended one: marking a plan unmarks the rest.
     *
     * @param  array{tagline: string|null, highlights: list<string>, is_recommended: bool, price_prefix: string|null, cta_label: string|null}  $data
     */
    public function updatePresentation(User $actor, int $planId, array $data, string $reason): void
    {
        DB::transaction(function () use ($actor, $planId, $data, $reason): void {
            $plan = DB::table('plans')->where('id', $planId)->lockForUpdate()->first() ?? abort(404);
            $highlights = array_values(array_filter(array_map(fn (string $line): string => trim($line), $data['highlights']), fn (string $line): bool => $line !== ''));
            if (count($highlights) > 6) {
                throw ValidationException::withMessages(['highlights' => 'Usa como máximo 6 frases destacadas.']);
            }
            if ($data['is_recommended']) {
                DB::table('plans')->where('id', '!=', $planId)->where('is_recommended', true)->update(['is_recommended' => false, 'updated_at' => now()]);
            }
            $values = ['tagline' => $data['tagline'], 'highlights' => json_encode($highlights), 'is_recommended' => $data['is_recommended'],
                'price_prefix' => $data['price_prefix'], 'cta_label' => $data['cta_label']];
            DB::table('plans')->where('id', $planId)->update([...$values, 'updated_at' => now()]);
            $this->audit->record($actor, 'plan.presentation_updated', 'plans', $planId,
                ['tagline' => $plan->tagline, 'highlights' => json_decode((string) ($plan->highlights ?? '[]'), true), 'is_recommended' => (bool) $plan->is_recommended,
                    'price_prefix' => $plan->price_prefix, 'cta_label' => $plan->cta_label],
                [...$values, 'highlights' => $highlights], $reason);
        });
    }

    public function setActive(User $actor, int $planId, bool $active, string $reason): void
    {
        $plan = DB::table('plans')->where('id', $planId)->first() ?? abort(404);
        if ($active && ! DB::table('plan_versions')->where('plan_id', $planId)->where('status', 'published')->exists()) {
            throw ValidationException::withMessages(['plan' => 'Publica una versión antes de activar el plan.']);
        }
        DB::table('plans')->where('id', $planId)->update(['is_active' => $active, 'updated_at' => now()]);
        DB::table('plan_prices')->where('plan_id', $planId)->update(['is_active' => $active, 'updated_at' => now()]);
        $this->audit->record($actor, $active ? 'plan.activated' : 'plan.deprecated', 'plans', $planId, ['is_active' => (bool) $plan->is_active], ['is_active' => $active], $reason, null, [], true);
    }

    /** The published version is the one sold: checkout reads plan_prices, feature_plan and plan_limits. */
    private function project(stdClass $version): void
    {
        $planId = (int) $version->plan_id;
        DB::table('plans')->where('id', $planId)->update(['name' => $version->name, 'description' => $version->description, 'updated_at' => now()]);
        foreach (['monthly' => $version->monthly_price, 'annual' => $version->annual_price] as $period => $amount) {
            DB::table('plan_prices')->updateOrInsert(['plan_id' => $planId, 'billing_period' => $period, 'currency_code' => $version->currency_code], ['amount' => $amount, 'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('feature_plan')->where('plan_id', $planId)->delete();
        foreach (DB::table('features')->whereIn('code', (array) json_decode((string) $version->entitlements, true))->pluck('id') as $featureId) {
            DB::table('feature_plan')->insert(['plan_id' => $planId, 'feature_id' => $featureId]);
        }
        $limits = (array) json_decode((string) $version->limits, true);
        DB::table('plan_limits')->where('plan_id', $planId)->whereNotIn('metric', array_keys($limits) ?: ['-'])->delete();
        foreach ($limits as $metric => $limit) {
            DB::table('plan_limits')->updateOrInsert(['plan_id' => $planId, 'metric' => $metric], ['limit_value' => $limit['value'] ?? null, 'unit' => self::METRICS[$metric]['unit'] ?? (string) ($limit['unit'] ?? ''),
                'warning_threshold' => (int) ($limit['warning'] ?? 80), 'high_threshold' => (int) ($limit['high'] ?? 95), 'enforcement' => (string) ($limit['enforcement'] ?? 'soft'), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    /** @param array<string, mixed> $data */
    private function insertVersion(User $actor, int $planId, int $version, array $data, string $note): int
    {
        $known = DB::table('features')->pluck('code')->all();
        $entitlements = array_values(array_unique(array_map('strval', (array) $data['entitlements'])));
        if (array_diff($entitlements, $known) !== []) {
            throw ValidationException::withMessages(['entitlements' => 'Hay funciones que no existen en el catálogo: '.implode(', ', array_diff($entitlements, $known)).'.']);
        }
        $limits = [];
        foreach ((array) $data['limits'] as $metric => $limit) {
            if (! isset(self::METRICS[$metric])) {
                throw ValidationException::withMessages(['limits' => 'Métrica desconocida: '.$metric.'.']);
            }
            $value = $limit['value'] ?? null;
            $limits[$metric] = ['value' => $value === null || $value === '' ? null : max(0, (int) $value), 'unit' => self::METRICS[$metric]['unit'],
                'warning' => max(1, min(100, (int) ($limit['warning'] ?? 80))), 'high' => max(1, min(100, (int) ($limit['high'] ?? 95))),
                'enforcement' => in_array($limit['enforcement'] ?? 'soft', ['soft', 'hard'], true) ? (string) ($limit['enforcement'] ?? 'soft') : 'soft'];
        }
        if ((float) $data['monthly_price'] < 0 || (float) $data['annual_price'] < 0) {
            throw ValidationException::withMessages(['monthly_price' => 'Los precios no pueden ser negativos.']);
        }

        return DB::table('plan_versions')->insertGetId(['plan_id' => $planId, 'version' => $version, 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'currency_code' => 'GTQ', 'monthly_price' => $data['monthly_price'], 'annual_price' => $data['annual_price'], 'entitlements' => json_encode($entitlements, JSON_THROW_ON_ERROR),
            'limits' => json_encode($limits, JSON_THROW_ON_ERROR), 'status' => 'draft', 'change_note' => $note, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
