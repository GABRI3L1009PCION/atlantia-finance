<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Link that confirms the firm owns the address its clients' replies will reach. */
class ClientReplyVerificationMail extends Mailable
{
    public function __construct(public string $firm, public string $url, public int $hours) {}

    public function build(): static
    {
        return $this->subject('Confirma el correo de '.$this->firm.' para tus clientes')
            ->view('emails.client-reply-verification', ['firm' => $this->firm, 'url' => $this->url, 'hours' => $this->hours]);
    }
}
