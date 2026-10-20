<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Email with the alerts of a person: obligations, receivables and payables that need attention. */
class AlertDigestMail extends Mailable
{
    /** @param list<array{kind: string, title: string, detail: string}> $items */
    public function __construct(public string $subjectLine, public string $recipientName, public array $items) {}

    public function build(): static
    {
        return $this->subject($this->subjectLine)->view('emails.alert-digest', ['name' => $this->recipientName, 'items' => $this->items]);
    }
}
