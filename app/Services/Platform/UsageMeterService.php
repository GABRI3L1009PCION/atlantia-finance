<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Real usage of each organization against its effective limits. Every metric is computed on the server from the
 * source tables (no separate counters to drift). Exceeding a limit marks the metric OVER_LIMIT: existing records are
 * never deleted and only the related new action is blocked when the limit is hard.
 */
class UsageMeterService
{
    public const LABELS = ['users' => 'Usuarios', 'offices' => 'Oficinas', 'active_cases' => 'Empresas', 'storage_bytes' => 'Almacenamiento',
        'ai_queries' => 'Consultas de Luzma (mes)', 'api_requests' => 'Solicitudes de API (mes)', 'documents' => 'Documentos'];

    public function __construct(private TenantDatabaseContext $tenancy, private EntitlementResolverService $entitlements) {}

    /** @return array<string, int> */
    public function measure(int $organizationId): array
    {
        return $this->tenancy->runAsSystem(function () use ($organizationId): array {
            $month = now()->startOfMonth();
            $files = 0;
            $documents = 0;
            foreach (['client_document_versions', 'journal_entry_documents', 'operational_record_files', 'purchase_document_files'] as $table) {
                $row = DB::table($table)->where($table.'.organization_id', $organizationId)->selectRaw('CAST(COALESCE(SUM(size_bytes), 0) AS BIGINT) as bytes, CAST(COUNT(*) AS BIGINT) as total')->first();
                $files += (int) ($row->bytes ?? 0);
                $documents += (int) ($row->total ?? 0);
            }

            return [
                'users' => DB::table('organization_user')->where('organization_user.organization_id', $organizationId)->where('status', 'active')->count(),
                'offices' => DB::table('offices')->where('offices.organization_id', $organizationId)->whereNull('deleted_at')->count(),
                'active_cases' => DB::table('companies')->where('companies.organization_id', $organizationId)->count(),
                'storage_bytes' => $files,
                'documents' => $documents,
                'ai_queries' => DB::table('luzma_messages')->where('luzma_messages.organization_id', $organizationId)->where('role', 'user')->where('created_at', '>=', $month)->count(),
                'api_requests' => DB::table('api_request_logs')->where('api_request_logs.organization_id', $organizationId)->where('created_at', '>=', $month)->count(),
            ];
        });
    }

    /**
     * Usage with limits, percentage and status (ok, warning, high, over_limit, unlimited).
     *
     * @return list<array{metric: string, label: string, value: int, limit: int|null, percent: int|null, status: string, enforcement: string, unit: string}>
     */
    public function usage(int $organizationId): array
    {
        $values = $this->measure($organizationId);
        $limits = $this->entitlements->resolve($organizationId)['limits'];
        $rows = [];
        foreach (self::LABELS as $metric => $label) {
            $limit = $limits[$metric] ?? null;
            $value = $values[$metric] ?? 0;
            $max = $limit['value'] ?? null;
            $percent = $max === null || $max === 0 ? null : (int) floor($value * 100 / $max);
            $rows[] = ['metric' => $metric, 'label' => $label, 'value' => $value, 'limit' => $limit === null ? null : $max, 'percent' => $percent,
                'status' => $this->status($value, $limit), 'enforcement' => (string) ($limit['enforcement'] ?? 'soft'), 'unit' => (string) ($limit['unit'] ?? ($metric === 'storage_bytes' ? 'bytes' : ''))];
        }

        return $rows;
    }

    /**
     * Blocks only the new action related to a hard limit (e.g. a new upload when storage is full). Closings, entries or
     * existing obligations are never blocked by an unrelated limit.
     */
    public function assertCanAdd(int $organizationId, string $metric, int $increment = 1): void
    {
        $limit = $this->entitlements->resolve($organizationId)['limits'][$metric] ?? null;
        if ($limit === null || $limit['value'] === null || $limit['enforcement'] !== 'hard') {
            return;
        }
        $current = $this->measure($organizationId)[$metric] ?? 0;
        if ($current + $increment > $limit['value']) {
            throw ValidationException::withMessages([$metric => 'Se alcanzó el límite de '.mb_strtolower(self::LABELS[$metric] ?? $metric).' del plan. Los datos existentes se conservan; amplía el plan para agregar más.']);
        }
    }

    /** Stores today's snapshot of every metric (history and trends). */
    public function snapshot(int $organizationId): void
    {
        $now = now();
        $this->tenancy->runAsSystem(function () use ($organizationId, $now): void {
            foreach ($this->usage($organizationId) as $row) {
                DB::table('usage_snapshots')->insert(['organization_id' => $organizationId, 'metric' => $row['metric'], 'value' => $row['value'], 'limit_value' => $row['limit'],
                    'percent' => $row['percent'] === null ? null : min(65535, $row['percent']), 'status' => $row['status'], 'captured_at' => $now]);
            }
        });
    }

    /** @param array{value: int|null, warning: int, high: int, enforcement: string}|null $limit */
    private function status(int $value, ?array $limit): string
    {
        if ($limit === null) {
            return 'not_included';
        }
        if ($limit['value'] === null) {
            return 'unlimited';
        }
        if ($limit['value'] === 0) {
            return $value > 0 ? 'over_limit' : 'not_included';
        }
        $percent = $value * 100 / $limit['value'];

        return match (true) {
            $value > $limit['value'] => 'over_limit',
            $percent >= 100 => 'at_limit',
            $percent >= $limit['high'] => 'high',
            $percent >= $limit['warning'] => 'warning',
            default => 'ok',
        };
    }
}
