<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Mail\FelInvoiceMail;
use App\Models\FelDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FelEmailService
{
    public function __construct(private FelPdfService $pdf, private FelAudit $audit) {}

    public function send(int $companyId, string $uuid): void
    {
        $firstAttempt = DB::transaction(function () use ($companyId, $uuid): bool {
            $doc = FelDocument::forCompany($companyId)->where('public_id', $uuid)->lockForUpdate()->firstOrFail();
            if ($doc->status !== FelDocumentStatus::Certified || $doc->email_sent_at || empty($doc->receiver_snapshot['email'])) {
                return true;
            }
            $path = $this->pdf->path($doc);
            if ($doc->pdf_path !== $path || ! Storage::disk('local')->exists($path)) {
                throw new RuntimeException('El PDF del documento no está disponible.');
            }
            if ($doc->email_started_at !== null) {
                return false;
            }
            $doc->forceFill(['email_started_at' => now()])->save();

            return true;
        });
        DB::transaction(function () use ($companyId, $uuid, $firstAttempt): void {
            $doc = FelDocument::forCompany($companyId)->where('public_id', $uuid)->lockForUpdate()->firstOrFail();
            if ($doc->status !== FelDocumentStatus::Certified || $doc->email_sent_at) {
                return;
            }
            if (! $firstAttempt && (config('mail.default') !== 'resend' || CarbonImmutable::parse($doc->email_started_at)->lt(now()->subHours(23)))) {
                $doc->forceFill(['email_status' => 'needs_review'])->save();

                return;
            }
            $email = $doc->receiver_snapshot['email'] ?? null;
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $doc->forceFill(['email_status' => 'no_recipient'])->save();

                return;
            }
            $path = $this->pdf->path($doc);
            if ($doc->pdf_path !== $path || ! Storage::disk('local')->exists($path)) {
                throw new RuntimeException('El PDF del documento no está disponible.');
            }
            $content = Storage::disk('local')->get($path);
            if (config('mail.default') === 'resend') {
                $key = config('services.resend.key');
                if (! is_string($key) || $key === '') {
                    throw new RuntimeException('Resend no está configurado.');
                }
                $response = Http::withToken($key)->withHeaders(['Idempotency-Key' => 'fel/'.$uuid])
                    ->connectTimeout(5)->timeout(20)->post('https://api.resend.com/emails', [
                        'from' => config('mail.from.address'), 'to' => [$email],
                        'subject' => 'PRUEBA FEL · '.$doc->series.'-'.$doc->number,
                        'html' => view('fel.email', ['document' => $doc])->render(),
                        'attachments' => [['filename' => 'FEL-PRUEBA-'.$doc->number.'.pdf', 'content' => base64_encode($content)]],
                    ]);
                if (! $response->successful() || ! is_string($response->json('id'))) {
                    throw new RuntimeException('Resend no confirmó la recepción del correo.');
                }
            } else {
                Mail::to($email)->send(new FelInvoiceMail($doc, $content));
            }
            $doc->forceFill(['email_status' => 'sent', 'email_sent_at' => now()])->save();
            $this->audit->record($doc, 'email_sent');
        }, attempts: 1);
    }
}
