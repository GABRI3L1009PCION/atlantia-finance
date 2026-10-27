<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Receivables\CollectionAutomationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Automatic collection rules of one company and the per-customer pause, from the company's customer screens.
 */
class CollectionAutomationController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private CollectionAutomationService $automation,
    ) {}

    public function update(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'upcoming_days' => 'nullable|integer|min:1|max:30',
            'overdue_steps' => 'present|array|max:6',
            'overdue_steps.*' => 'integer|min:1|max:365',
            'promise_reminder' => 'required|boolean',
            'broken_promise' => 'required|boolean',
            'monthly_statement' => 'required|boolean',
            'min_overdue_cents' => 'required|integer|min:0|max:100000000000',
            'quiet_days' => 'required|integer|min:1|max:30',
        ]);
        DB::transaction(fn () => $this->automation->save($organization, $request->user(), $record->id, [
            'enabled' => (bool) $data['enabled'], 'upcoming_days' => isset($data['upcoming_days']) ? (int) $data['upcoming_days'] : null,
            'overdue_steps' => array_values(array_map('intval', $data['overdue_steps'])), 'promise_reminder' => (bool) $data['promise_reminder'],
            'broken_promise' => (bool) $data['broken_promise'], 'monthly_statement' => (bool) $data['monthly_statement'],
            'min_overdue_cents' => (int) $data['min_overdue_cents'], 'quiet_days' => (int) $data['quiet_days'],
        ]));

        return back()->with('success', $data['enabled'] ? 'Cobranza automática encendida. Los recordatorios salen cada mañana.' : 'Cobranza automática guardada y apagada.');
    }

    public function pause(Request $request, string $company, int $customer): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company);
        $paused = (bool) $request->validate(['paused' => 'required|boolean'])['paused'];
        abort_unless(DB::table('sales_customers')->where('company_id', $record->id)->where('id', $customer)->exists(), 404);
        DB::transaction(fn () => $this->automation->pause($organization, $request->user(), $record->id, $customer, $paused));

        return back()->with('success', $paused ? 'Este cliente ya no recibe recordatorios automáticos.' : 'Este cliente vuelve a recibir recordatorios automáticos.');
    }

    /** @return array{0: Organization, 1: stdClass} */
    private function scope(Request $request, string $company): array
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'basic_invoicing');
        $record = $this->accounting->company($organization, $request->user(), $company, true);
        $this->accounting->assertModule($organization, $record->id, $request->user(), 'sales.manage');

        return [$organization, $record];
    }
}
