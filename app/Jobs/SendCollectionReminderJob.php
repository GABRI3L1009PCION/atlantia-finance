<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\CollectionReminderMail;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delivers a logged collection reminder (or statement, with its PDF) through the queue, with retries. The log row moves
 * from "queued" to "sent", or to "failed" with the error once the retries are exhausted.
 */
class SendCollectionReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 60;

    public function __construct(public int $reminderId, public ?string $attachmentPath = null) {}

    public function handle(TenantDatabaseContext $tenancy): void
    {
        $tenancy->runAsSystem(function (): void {
            $reminder = DB::table('collection_reminders')->where('id', $this->reminderId)->first();
            if ($reminder === null || $reminder->status === 'sent') {
                return;
            }
            $attachment = $this->attachmentPath !== null && Storage::disk('local')->exists($this->attachmentPath) ? Storage::disk('local')->get($this->attachmentPath) : null;
            Mail::to((string) $reminder->recipient)->send(new CollectionReminderMail((string) $reminder->subject, (string) $reminder->body, $attachment, $attachment === null ? null : 'estado-de-cuenta-'.$reminder->sales_customer_id.'.pdf'));
            DB::table('collection_reminders')->where('id', $this->reminderId)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('collection_reminders')->where('id', $this->reminderId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => mb_substr((string) $exception?->getMessage(), 0, 500) ?: 'No fue posible entregar el correo.', 'updated_at' => now()]));
    }
}
