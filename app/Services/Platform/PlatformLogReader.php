<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * System logs for the Control Center: application log lines (parsed from the Laravel log), failed jobs, failed
 * webhook deliveries and provider events. Everything is sanitized: keys, tokens, passwords and card-like numbers are
 * masked before they reach the screen.
 */
class PlatformLogReader
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(private TenantDatabaseContext $tenancy) {}

    public static function sanitize(string $text): string
    {
        $patterns = [
            '/\b(atl_[a-z0-9]{8})_[A-Za-z0-9]{40}\b/' => '$1_••••',
            '/\bwhsec_[A-Za-z0-9]{8,}\b/' => 'whsec_••••',
            '/\b((?:sk|pk|rk)_(?:live|test)_)[A-Za-z0-9]{6,}\b/' => '$1••••',
            '/(Bearer\s+)[A-Za-z0-9._\-]{10,}/i' => '$1••••',
            '/("?(?:password|passwd|secret|token|api[_-]?key|authorization|client_secret|private_key)"?\s*[:=]\s*"?)[^",\s}]+/i' => '$1••••',
            '/\b(\d{4})\d{8,11}(\d{4})\b/' => '$1••••$2',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * @param  array{source?: string|null, severity?: string|null, search?: string|null, correlation_id?: string|null, organization_id?: int|null, date?: string|null}  $filters
     * @return list<array{at: string, source: string, severity: string, message: string, context: string|null, correlation_id: string|null, organization_id: int|null}>
     */
    public function entries(array $filters, int $limit = 200): array
    {
        $source = $filters['source'] ?? null;
        $rows = [];
        if ($source === null || $source === 'application') {
            $rows = [...$rows, ...$this->application()];
        }
        if ($source === null || $source === 'queue') {
            $rows = [...$rows, ...$this->tenancy->runAsSystem(fn () => DB::table('failed_jobs')->orderByDesc('id')->limit(100)->get()->map(fn ($row): array => [
                'at' => Carbon::parse($row->failed_at)->toIso8601String(), 'source' => 'queue', 'severity' => 'error',
                'message' => self::sanitize(strtok((string) $row->exception, "\n") ?: 'Job fallido'), 'context' => (string) (json_decode((string) $row->payload, true)['displayName'] ?? ''),
                'correlation_id' => json_decode((string) $row->payload, true)['atlantia']['correlation_id'] ?? null, 'organization_id' => json_decode((string) $row->payload, true)['atlantia']['organization_id'] ?? null])->all())];
        }
        if ($source === null || $source === 'integration') {
            $rows = [...$rows, ...$this->tenancy->runAsSystem(fn () => DB::table('webhook_deliveries')->whereNotNull('error')->orderByDesc('id')->limit(100)->get(['organization_id', 'event', 'error', 'updated_at'])
                ->map(fn ($row): array => ['at' => Carbon::parse($row->updated_at)->toIso8601String(), 'source' => 'integration', 'severity' => 'warning', 'message' => self::sanitize((string) $row->error),
                    'context' => 'Webhook '.$row->event, 'correlation_id' => null, 'organization_id' => (int) $row->organization_id])->all())];
        }
        if ($source === null || $source === 'provider') {
            $rows = [...$rows, ...$this->tenancy->runAsSystem(fn () => DB::table('webhook_events')->where('status', 'failed')->orderByDesc('id')->limit(100)->get(['provider', 'event_type', 'error_message', 'failed_at'])
                ->map(fn ($row): array => ['at' => Carbon::parse($row->failed_at)->toIso8601String(), 'source' => 'provider', 'severity' => 'error', 'message' => self::sanitize((string) $row->error_message),
                    'context' => $row->provider.' · '.$row->event_type, 'correlation_id' => null, 'organization_id' => null])->all())];
        }
        if ($source === null || $source === 'security') {
            $rows = [...$rows, ...$this->tenancy->runAsSystem(fn () => DB::table('login_attempts')->where('succeeded', false)->orderByDesc('id')->limit(100)->get()
                ->map(fn ($row): array => ['at' => Carbon::parse($row->created_at)->toIso8601String(), 'source' => 'security', 'severity' => 'warning', 'message' => 'Inicio de sesión fallido',
                    'context' => ($row->email ? self::maskEmail((string) $row->email) : '—').' desde '.$row->ip, 'correlation_id' => null, 'organization_id' => null])->all())];
        }

        $severity = $filters['severity'] ?? null;
        $minimum = $severity === null ? 0 : (int) array_search($severity, self::LEVELS, true);
        $rows = array_values(array_filter($rows, function (array $row) use ($filters, $severity, $minimum): bool {
            if ($severity !== null && (int) array_search($row['severity'], self::LEVELS, true) < $minimum) {
                return false;
            }
            if (! empty($filters['correlation_id']) && $row['correlation_id'] !== $filters['correlation_id']) {
                return false;
            }
            if (! empty($filters['organization_id']) && (int) $row['organization_id'] !== (int) $filters['organization_id']) {
                return false;
            }
            if (! empty($filters['date']) && ! str_starts_with($row['at'], (string) $filters['date'])) {
                return false;
            }

            return empty($filters['search']) || str_contains(mb_strtolower($row['message'].' '.$row['context']), mb_strtolower((string) $filters['search']));
        }));
        usort($rows, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, $limit);
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).'•••@'.$domain;
    }

    /** @return list<array{at: string, source: string, severity: string, message: string, context: string|null, correlation_id: string|null, organization_id: int|null}> */
    private function application(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $size = filesize($path) ?: 0;
        fseek($handle, max(0, $size - 400_000));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        $entries = [];
        foreach (preg_split('/\n(?=\[\d{4}-\d{2}-\d{2}[ T])/', $chunk) ?: [] as $block) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.+\-]+)\]\s+\w+\.(\w+):\s+(.*)$/s', trim($block), $match) !== 1) {
                continue;
            }
            $firstLine = strtok($match[3], "\n") ?: '';
            preg_match('/"correlation_id":"([^"]+)"/', $match[3], $correlation);
            preg_match('/"organization_id":(\d+)/', $match[3], $organization);
            $context = preg_match('/(\{.*\})\s*(\[\])?\s*$/s', $firstLine, $json) === 1 ? $json[1] : null;
            $entries[] = ['at' => Carbon::parse($match[1])->toIso8601String(), 'source' => 'application', 'severity' => strtolower($match[2]),
                'message' => self::sanitize(mb_strimwidth($context === null ? $firstLine : trim(str_replace($context, '', $firstLine)), 0, 500, '…')),
                'context' => $context === null ? null : self::sanitize(mb_strimwidth($context, 0, 500, '…')), 'correlation_id' => $correlation[1] ?? null,
                'organization_id' => isset($organization[1]) ? (int) $organization[1] : null];
        }

        return array_slice(array_reverse($entries), 0, 300);
    }
}
