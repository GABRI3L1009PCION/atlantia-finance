<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Security notice to the firm's owners about a client company sent to the trash, restored, about to be purged or purged. */
class CompanyDeletionMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(public string $title, public array $lines, public string $name) {}

    public function build(): static
    {
        return $this->subject($this->title)->view('emails.company-deletion', ['title' => $this->title, 'lines' => $this->lines, 'name' => $this->name, 'url' => url('/office/companies')]);
    }
}
