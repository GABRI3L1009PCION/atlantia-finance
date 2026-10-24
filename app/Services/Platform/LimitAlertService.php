<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Tenancy\TenantDatabaseContext;
use App\Support\PlatformIssuer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tells the owners of a firm, in the app and by email, when a plan limit is almost reached: once at 80 % and once at 95 %
 * for each resource and billing period. A resource over its limit keeps the 95 % notice, never a stream of them.
 */
class LimitAlertService
{
    /** Resources that matter to the owner and the label used in the message. */
    private const METRICS = ['offices' => 'oficinas', 'users' => 'usuarios', 'active_cases' => 'empresas cliente', 'storage_bytes' => 'almacenamiento', 'ai_queries' => 'consultas de Luzma este mes',
        'api_requests' => 'solicitudes de API este mes'];

    private const THRESHOLDS = [95, 80];

    public function __construct(private TenantDatabaseContext $tenancy, private UsageMeterService $usage, private NotificationTemplateService $templates) {}

    /** Checks every organization with an active plan. Returns how many notices were sent now. */
    public function run(): int
    {
        $ids = $this->tenancy->runAsSystem(fn (): array => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->whereNull('deleted_at')->pluck('id')->all());
        $sent = 0;
        foreach ($ids as $id) {
            $sent += $this->check((int) $id);
        }

        return $sent;
    }

    public function check(int $organizationId): int
    {
        $period = $this->billingPeriodKey($organizationId);
        $sent = 0;
        foreach ($this->usage->usage($organizationId) as $row) {
            if (! isset(self::METRICS[$row['metric']]) || $row['limit'] === null || $row['limit'] <= 0 || $row['percent'] === null) {
                continue;
            }
            $threshold = collect(self::THRESHOLDS)->first(fn (int $level): bool => $row['percent'] >= $level);
            if ($threshold === null) {
                continue;
            }
            // The resources that reset every month (Luzma) are announced once a month; the others once per billing period.
            $key = 'limit-alert:'.$organizationId.':'.$row['metric'].':'.$threshold.':'.(in_array($row['metric'], ['ai_queries', 'api_requests'], true) ? now()->format('Y-m') : $period);
            if (! Cache::add($key, true, now()->addDays(400))) {
                continue;
            }
            $this->templates->notifyOrganization($organizationId, 'limit_warning', [
                'metric' => self::METRICS[$row['metric']], 'percent' => $row['percent'], 'used' => $this->amount($row), 'limit' => $this->amount($row, true),
                'plans_url' => url('/office/subscription'),
            ]);
            $sent++;
        }

        return $sent;
    }

    private function billingPeriodKey(int $organizationId): string
    {
        $start = $this->tenancy->runAsSystem(fn () => DB::table('subscriptions')->where('organization_id', $organizationId)->whereIn('status', ['active', 'trialing', 'past_due', 'grace_period'])
            ->orderByDesc('id')->value('current_period_starts_at'));

        return $start === null ? 'sin-periodo' : substr((string) $start, 0, 10);
    }

    /** @param array{metric: string, value: int, limit: int|null} $row */
    private function amount(array $row, bool $limit = false): string
    {
        $value = $limit ? (int) $row['limit'] : $row['value'];

        return $row['metric'] === 'storage_bytes' ? number_format($value / 1073741824, 2).' GB' : number_format($value);
    }
}
