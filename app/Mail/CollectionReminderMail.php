<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Collection reminder or statement sent to a company's customer, rendered from the firm's templates. */
class CollectionReminderMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $body,
        private ?string $attachment = null,
        private ?string $attachmentName = null,
    ) {}

    public function build(): static
    {
        $mail = $this->subject($this->subjectLine)->view('emails.collection-reminder', ['body' => $this->body]);
        if ($this->attachment !== null) {
            $mail->attachData($this->attachment, $this->attachmentName ?? 'estado-de-cuenta.pdf', ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
