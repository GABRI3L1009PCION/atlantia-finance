<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** A firm asks for a quote for the custom plan: it reaches the team's inbox and replying answers the firm. */
class QuoteRequestMail extends Mailable
{
    /** @param  array{name: string, firm: string, email: string, phone: string|null, offices: string|null, message: string|null}  $request */
    public function __construct(public array $request)
    {
        $this->subject('Solicitud de cotización: '.$request['firm']);
        $this->replyTo($request['email'], $request['name']);
    }

    public function build(): static
    {
        return $this->view('emails.quote-request');
    }
}
