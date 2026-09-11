<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CertifyFelDocumentJob;
use App\Jobs\GenerateInvoicePdfJob;
use App\Jobs\GenerateSubscriptionFelInvoiceJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\FelDocument;
use App\Services\Fel\FelIssuerSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverFelDocuments extends Command
{
    protected $signature = 'fel:recover';

    protected $description = 'Reencola operaciones FEL pendientes; las fallidas requieren revisión explícita.';

    public function handle(): int
    {
        FelDocument::query()->where('updated_at', '<', now()->subMinutes(5))->whereIn('status', ['pending', 'processing'])
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $doc) {
                    CertifyFelDocumentJob::dispatch($doc->company_id, $doc->public_id);
                }
            });
        FelDocument::query()->where('updated_at', '<', now()->subMinutes(5))->whereIn('status', ['certified', 'cancelled'])
            ->where('pdf_status', 'pending')->chunkById(100, function ($documents): void {
                foreach ($documents as $doc) {
                    GenerateInvoicePdfJob::dispatch($doc->company_id, $doc->public_id);
                }
            });
        FelDocument::query()->where('updated_at', '<', now()->subMinutes(5))->where('status', 'certified')
            ->where('pdf_status', 'ready')->where('email_status', 'pending')->whereNull('email_sent_at')
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $doc) {
                    SendInvoiceEmailJob::dispatch($doc->company_id, $doc->public_id);
                }
            });
        if (app(FelIssuerSettings::class)->platformCompanyUuid()) {
            DB::table('fel_subscription_requests')->whereIn('status', ['pending', 'needs_configuration'])
                ->orderBy('payment_id')->chunk(100, function ($requests): void {
                    foreach ($requests as $request) {
                        GenerateSubscriptionFelInvoiceJob::dispatch($request->payment_id);
                    }
                });
        }

        return self::SUCCESS;
    }
}
