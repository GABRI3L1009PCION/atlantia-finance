<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use stdClass;

class ApproveAccountingPeriodReopening
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

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['accounting' => 'La solicitud de reapertura no está pendiente.']);
            }

            DB::table('accounting_period_reopening_requests')->where('id', $requestId)->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('accounting_periods')->where('id', $request->accounting_period_id)->update(['status' => 'reopen_approved', 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'accounting_period.reopen_approved', 'accounting_periods', (int) $request->accounting_period_id, ['request_id' => $requestId]);
            Event::dispatch('accounting.period.reopen_approved', [$company->id, $request->accounting_period_id, $requestId]);

            return new AccountingPeriodActionResult(true, 'Solicitud de reapertura aprobada.', ['request_id' => $requestId]);
        });
    }
}
