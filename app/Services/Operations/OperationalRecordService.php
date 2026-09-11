<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationalRecord;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class OperationalRecordService
{
    public function __construct(
        private OperationalWorkflowRegistry $workflows,
        private WorkspaceContext $workspace,
        private AccountingService $accounting,
    ) {}

    /**
     * @param  array{request_id: string, record_type: string, title: string, effective_on: string, amount?: string, currency?: string, reference?: string|null, data?: array<string, mixed>}  $data
     */
    public function create(Organization $organization, User $user, stdClass $company, string $module, array $data): OperationalRecord
    {
        $definition = $this->authorizeModule($organization, $module);
        $existing = OperationalRecord::query()->where('company_id', $company->id)
            ->where('module', $module)->where('idempotency_key', $data['request_id'])->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($organization, $user, $company, $module, $data, $definition): OperationalRecord {
            $this->accounting->lock($company->id);
            $existing = OperationalRecord::query()->where('company_id', $company->id)
                ->where('module', $module)->where('idempotency_key', $data['request_id'])->first();
            if ($existing !== null) {
                return $existing;
            }

            $periodId = $this->periodId($company->id, $data['effective_on'], $definition['period_guard']);
            $record = OperationalRecord::query()->create([
                'company_id' => $company->id,
                'accounting_period_id' => $periodId,
                'module' => $module,
                'record_type' => $data['record_type'],
                'reference' => $data['reference'] ?? null,
                'title' => $data['title'],
                'status' => $definition['initial'],
                'effective_on' => $data['effective_on'],
                'currency' => $data['currency'] ?? $company->currency,
                'amount_cents' => $this->accounting->cents($data['amount'] ?? '0'),
                'data' => $data['data'] ?? [],
                'idempotency_key' => $data['request_id'],
                'created_by' => $user->id,
            ]);
            if ($record->reference === null) {
                $record->forceFill(['reference' => Str::upper(Str::substr($module, 0, 4)).'-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT)])->save();
            }

            $this->event($record, $user, 'created', null, $record->status, null, [], $record->toArray(), $data['request_id']);
            $this->accounting->audit($organization, $user, $module.'.created', 'operational_records', $record->id, [
                'public_id' => $record->public_id,
                'status' => $record->status,
            ]);

            return $record->load('events');
        });
    }

    /**
     * @param  array{title?: string, effective_on?: string, amount?: string, currency?: string, reference?: string|null, data?: array<string, mixed>}  $data
     */
    public function update(Organization $organization, User $user, stdClass $company, OperationalRecord $record, array $data): OperationalRecord
    {
        $definition = $this->authorizeModule($organization, $record->module);
        if ($record->status !== $definition['initial']) {
            throw ValidationException::withMessages(['record' => 'Sólo se puede editar un registro en su estado inicial.']);
        }

        return DB::transaction(function () use ($organization, $user, $company, $record, $data, $definition): OperationalRecord {
            $record = OperationalRecord::query()->where('company_id', $company->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $before = $record->toArray();
            $effectiveOn = $data['effective_on'] ?? $record->effective_on->toDateString();
            $values = array_filter([
                'title' => $data['title'] ?? null,
                'effective_on' => $data['effective_on'] ?? null,
                'currency' => $data['currency'] ?? null,
                'reference' => array_key_exists('reference', $data) ? $data['reference'] : null,
                'data' => $data['data'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
            if (array_key_exists('amount', $data)) {
                $values['amount_cents'] = $this->accounting->cents($data['amount']);
            }
            $values['accounting_period_id'] = $this->periodId($company->id, $effectiveOn, $definition['period_guard']);
            $values['updated_by'] = $user->id;
            $values['version'] = $record->version + 1;
            $record->fill($values)->save();
            $eventKey = (string) Str::uuid();
            $this->event($record, $user, 'updated', $record->status, $record->status, null, $before, $record->fresh()->toArray(), $eventKey);
            $this->accounting->audit($organization, $user, $record->module.'.updated', 'operational_records', $record->id, ['version' => $record->version]);

            return $record->fresh(['events']) ?? $record;
        });
    }

    public function transition(
        Organization $organization,
        User $user,
        stdClass $company,
        OperationalRecord $record,
        string $target,
        string $idempotencyKey,
        ?string $reason,
    ): OperationalRecord {
        $definition = $this->authorizeModule($organization, $record->module);
        if (in_array($target, $definition['privileged'], true) && $organization->pivot->role !== 'owner') {
            abort(403, 'Esta transición requiere aprobación del propietario.');
        }
        if (in_array($target, $definition['reasons'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de esta transición.']);
        }

        return DB::transaction(function () use ($organization, $user, $company, $record, $target, $idempotencyKey, $reason, $definition): OperationalRecord {
            $record = OperationalRecord::query()->where('company_id', $company->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->events()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $record->fresh(['events']) ?? $record;
            }
            if ($record->status === $target) {
                return $record;
            }
            if (! in_array($target, $definition['transitions'][$record->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "No se permite cambiar de {$record->status} a {$target}."]);
            }

            $this->periodId($company->id, $record->effective_on->toDateString(), $definition['period_guard'] && $target !== 'reopened');
            $before = $record->toArray();
            $values = [
                'status' => $target,
                'updated_by' => $user->id,
                'version' => $record->version + 1,
            ];
            if (in_array($target, ['approved', 'ready', 'issued', 'published'], true)) {
                $values['approved_by'] = $user->id;
                $values['approved_at'] = now();
            }
            if (in_array($target, ['closed', 'completed'], true)) {
                $values['closed_by'] = $user->id;
                $values['closed_at'] = now();
            }
            if ($target === 'reopened') {
                $values['closed_by'] = null;
                $values['closed_at'] = null;
            }
            $record->forceFill($values)->save();
            $this->synchronizeAccountingPeriod($record, $target, $user);
            $this->event($record, $user, 'transitioned', $before['status'], $target, $reason, $before, $record->fresh()->toArray(), $idempotencyKey);
            $this->accounting->audit($organization, $user, $record->module.'.transitioned', 'operational_records', $record->id, [
                'from' => $before['status'], 'to' => $target, 'reason' => $reason,
            ]);

            return $record->fresh(['events']) ?? $record;
        });
    }

    /** @return array{feature: string, initial: string, period_guard: bool, transitions: array<string, list<string>>, privileged: list<string>, reasons: list<string>} */
    private function authorizeModule(Organization $organization, string $module): array
    {
        $definition = $this->workflows->definition($module);
        $this->workspace->requireFeature($organization, $definition['feature']);

        return $definition;
    }

    private function periodId(int $companyId, string $date, bool $required): ?int
    {
        if (! $required) {
            return null;
        }
        $period = DB::table('accounting_periods')->where('company_id', $companyId)
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
        if (! $period || $period->closed_at !== null) {
            throw ValidationException::withMessages(['effective_on' => 'La operación requiere un período contable abierto que incluya la fecha.']);
        }

        return (int) $period->id;
    }

    private function synchronizeAccountingPeriod(OperationalRecord $record, string $target, User $user): void
    {
        if ($record->module !== 'cierre-periodo' || $record->accounting_period_id === null) {
            return;
        }
        if ($target === 'closed') {
            DB::table('accounting_periods')->where('id', $record->accounting_period_id)->whereNull('closed_at')
                ->update(['closed_at' => now(), 'closed_by' => $user->id, 'updated_at' => now()]);
        }
        if ($target === 'reopened') {
            DB::table('accounting_periods')->where('id', $record->accounting_period_id)
                ->update(['closed_at' => null, 'closed_by' => null, 'updated_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function event(OperationalRecord $record, User $user, string $action, ?string $from, ?string $to, ?string $reason, array $before, array $after, string $key): void
    {
        $record->events()->create([
            'company_id' => $record->company_id,
            'user_id' => $user->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
            'idempotency_key' => $key,
            'occurred_at' => now(),
        ]);
    }
}
