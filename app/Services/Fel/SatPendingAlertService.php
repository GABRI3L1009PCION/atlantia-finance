<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\Organization;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Warns the accountant in charge when a document prepared to be issued in the SAT portal has been waiting too long,
 * so no invoice stays prepared without being issued. Each document is warned about once a day at most.
 */
class SatPendingAlertService
{
    public const TYPE = 'fel.sat_pending';

    public function __construct(private WorkNotificationService $notifications) {}

    /** Creates the pending warnings of the organization; returns how many notices were created. */
    public function sweep(Organization $organization): int
    {
        $limit = now()->subDays(max(1, (int) config('fel.sat_pending_alert_days')));
        $notified = 0;

        $documents = DB::table('fel_documents')->join('companies', 'companies.id', '=', 'fel_documents.company_id')
            ->where('fel_documents.organization_id', $organization->id)->where('fel_documents.status', FelDocumentStatus::AwaitingSat->value)
            ->where('fel_documents.awaiting_sat_at', '<=', $limit)
            ->where(fn ($query) => $query->whereNull('fel_documents.sat_alerted_at')->orWhere('fel_documents.sat_alerted_at', '<=', now()->subDay()))
            ->get(['fel_documents.id', 'fel_documents.company_id', 'fel_documents.created_by', 'fel_documents.series', 'fel_documents.number', 'fel_documents.awaiting_sat_at', 'companies.name as company_name']);

        foreach ($documents as $document) {
            $recipients = DB::table('company_assignments')->where('company_id', $document->company_id)->where('assignment_role', 'accountant_in_charge')
                ->where('status', 'active')->whereNull('deleted_at')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $recipients = array_values(array_unique([...$recipients, (int) $document->created_by]));
            $days = (int) now()->diffInDays($document->awaiting_sat_at);

            foreach ($recipients as $userId) {
                $this->notifications->notify($organization->id, $userId, null, self::TYPE, 'Factura sin emitir en la SAT: '.$document->series.'-'.$document->number.' · '.$document->company_name, 'Lleva '.max(1, $days).' día(s) preparada. Emítela en el portal de la SAT y regístrala, o devuélvela a borrador.', 'fel_documents', (int) $document->id);
                $notified++;
            }

            DB::table('fel_documents')->where('id', $document->id)->update(['sat_alerted_at' => now()]);
        }

        return $notified;
    }
}
