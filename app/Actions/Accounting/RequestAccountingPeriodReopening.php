<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use stdClass;

class RequestAccountingPeriodReopening
{
    public function __construct(private AccountingService $accounting) {}

    /** $authorized: the caller already verified the periods.request_reopen permission, so a non-owner may request. */
    public function execute(Organization $organization, stdClass $company, int $periodId, User $actor, string $reason, bool $authorized = false): AccountingPeriodActionResult
    {
        if (! $authorized && $organization->pivot->role !== 'owner') {
            abort(403);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la reapertura.']);
        }

        return DB::transaction(function () use ($organization, $company, $periodId, $actor, $reason): AccountingPeriodActionResult {
            $this->accounting->lock((int) $company->id);
            $period = DB::table('accounting_periods')->where('company_id', $company->id)->where('id', $periodId)->firstOrFail();

            if (! $period->closed_at || ($period->status ?? 'closed') !== 'closed') {
                throw ValidationException::withMessages(['accounting' => 'Solo se puede solicitar reapertura de un período cerrado.']);
            }

            $existing = DB::table('accounting_period_reopening_requests')
                ->where('company_id', $company->id)->where('accounting_period_id', $periodId)
                ->where('status', 'pending')->first();
            if ($existing) {
                throw ValidationException::withMessages(['accounting' => 'Ya existe una solicitud de reapertura pendiente.']);
            }

            $requestId = DB::table('accounting_period_reopening_requests')->insertGetId([
                'company_id' => $company->id,
                'accounting_period_id' => $periodId,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('accounting_periods')->where('id', $periodId)->update(['status' => 'reopen_requested', 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'accounting_period.reopen_requested', 'accounting_periods', $periodId, ['request_id' => $requestId, 'reason' => $reason]);
            Event::dispatch('accounting.period.reopen_requested', [$company->id, $periodId, $requestId]);

            return new AccountingPeriodActionResult(true, 'Solicitud de reapertura registrada.', ['request_id' => $requestId]);
        });
    }
}
