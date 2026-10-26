<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Notifications\ClientMailIdentityService;
use Illuminate\Mail\Mailable;

/**
 * Collection reminder or statement sent to a company's customer, rendered from the firm's templates. It leaves from
 * Atlantia's sending address under the company's name, and replying reaches the firm.
 *
 * @phpstan-import-type ClientMailIdentity from ClientMailIdentityService
 */
class CollectionReminderMail extends Mailable
{
    /**
     * @param  ClientMailIdentity  $identity
     */
    public function __construct(
        public string $subjectLine,
        public string $body,
        public array $identity,
        private ?string $attachment = null,
        private ?string $attachmentName = null,
    ) {}

    public function build(): static
    {
        $mail = $this->from((string) config('mail.from.address'), $this->identity['from_name'])->subject($this->subjectLine)
            ->view('emails.collection-reminder', ['body' => $this->body, 'identity' => $this->identity]);
        if ($this->identity['reply_to'] !== null) {
            $mail->replyTo($this->identity['reply_to'], $this->identity['from_name']);
        }
        if ($this->attachment !== null) {
            $mail->attachData($this->attachment, $this->attachmentName ?? 'estado-de-cuenta.pdf', ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
