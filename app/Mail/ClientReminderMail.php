<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Notifications\ClientMailIdentityService;
use Illuminate\Mail\Mailable;

/**
 * Automatic reminder an office sends to its client before a deadline (a fee invoice, a requested document, a tax due
 * date). It leaves from Atlantia's sending address under the firm's name, and replying reaches the firm.
 *
 * @phpstan-import-type ClientMailIdentity from ClientMailIdentityService
 */
class ClientReminderMail extends Mailable
{
    /**
     * @param  ClientMailIdentity  $identity
     */
    public function __construct(public string $subjectLine, public string $body, public array $identity) {}

    public function build(): static
    {
        $mail = $this->from((string) config('mail.from.address'), $this->identity['from_name'])->subject($this->subjectLine)
            ->view('emails.client-reminder', ['body' => $this->body, 'identity' => $this->identity, 'portalUrl' => url('/client')]);
        if ($this->identity['reply_to'] !== null) {
            $mail->replyTo($this->identity['reply_to'], $this->identity['from_name']);
        }

        return $mail;
    }
}
