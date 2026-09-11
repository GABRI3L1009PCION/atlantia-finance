<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\FelDocument;
use Illuminate\Mail\Mailable;

class FelInvoiceMail extends Mailable
{
    public function __construct(public FelDocument $document, private string $pdf) {}

    public function build(): static
    {
        return $this->subject('PRUEBA FEL · '.$this->document->series.'-'.$this->document->number)
            ->view('fel.email')->attachData($this->pdf, 'FEL-PRUEBA-'.$this->document->number.'.pdf', ['mime' => 'application/pdf']);
    }
}
