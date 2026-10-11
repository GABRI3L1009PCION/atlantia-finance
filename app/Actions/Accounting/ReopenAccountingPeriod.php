<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use stdClass;

class ReopenAccountingPeriod
{
    public function __construct(private AccountingService $accounting) {}

    public function execute(Organization $organization, stdClass $company, int $requestId, User $actor): AccountingPeriodActionResult
    {
        if ($organization->pivot->role !== 'owner') {
            abort(403);
        }

        return DB::transaction(function () use ($organization, $company, $requestId, $actor): AccountingPeriodActionResult {
            $this->accounting->lock((int) $company->id);
            $request = DB::table('accounting_period_reopening_requests')
                ->where('company_id', $company->id)->where('id', $requestId)->firstOrFail();

            if ($request->status !== 'approved') {
                throw ValidationException::withMessages(['accounting' => 'La reapertura debe estar aprobada antes de ejecutarse.']);
            }

            $period = DB::table('accounting_periods')->where('company_id', $company->id)->where('id', $request->accounting_period_id)->firstOrFail();
            if (! $period->closed_at || ($period->status ?? 'closed') !== 'reopen_approved') {
                throw ValidationException::withMessages(['accounting' => 'El período no está en estado aprobado para reapertura.']);
            }

            DB::table('accounting_periods')->where('id', $period->id)->update([
                'status' => 'reopened',
                'closed_at' => null,
                'closed_by' => null,
                'updated_at' => now(),
            ]);
            DB::table('accounting_period_reopening_requests')->where('id', $requestId)->update([
                'status' => 'executed',
                'executed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'accounting_period.reopened', 'accounting_periods', (int) $period->id, ['request_id' => $requestId, 'reason' => $request->reason]);
            Event::dispatch('accounting.period.reopened', [$company->id, $period->id, $requestId]);

            return new AccountingPeriodActionResult(true, 'Período reabierto con trazabilidad.', ['request_id' => $requestId]);
        });
    }
}
