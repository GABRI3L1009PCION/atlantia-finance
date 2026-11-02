<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends WhatsApp messages through the WhatsApp Business Cloud API (Meta). A message the business starts has to use an
 * approved template, so the reminder text travels as the template's single body parameter. Without credentials the
 * channel is simply unavailable and nothing is sent.
 */
class WhatsAppService
{
    public function configured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * Digits of a phone in international format, adding the default country code to local numbers (8 digits in
     * Guatemala). Returns null when it cannot be a phone number.
     */
    public function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) === 8) {
            $digits = config('services.whatsapp.default_country_code', '502').$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * Sends the template with the text. Returns null when sent, or the reason it was not.
     */
    public function sendTemplate(string $phone, string $text): ?string
    {
        if (! $this->configured()) {
            return 'WhatsApp no está configurado.';
        }

        try {
            $response = Http::withToken((string) config('services.whatsapp.token'))->acceptJson()->timeout(15)
                ->post(rtrim((string) config('services.whatsapp.url'), '/').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'template',
                    'template' => [
                        'name' => (string) config('services.whatsapp.template'),
                        'language' => ['code' => (string) config('services.whatsapp.language')],
                        'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($text, 0, 1000)]]]],
                    ],
                ]);
        } catch (Throwable $exception) {
            return mb_substr('No se pudo contactar a WhatsApp: '.$exception->getMessage(), 0, 255);
        }

        return $response->successful() ? null : mb_substr('WhatsApp respondió '.$response->status().': '.(string) $response->json('error.message', ''), 0, 255);
    }
}
