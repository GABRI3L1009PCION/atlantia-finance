<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Carries the sample invoice (see FelPreviewService): it only shows how a sent invoice looks. */
class FelPreviewMail extends Mailable
{
    public function __construct(private string $pdf) {}

    public function build(): static
    {
        return $this->subject('Vista previa de factura electrónica | Atlantia Finance')
            ->view('fel.preview-email')
            ->attachData($this->pdf, 'Vista-previa-factura-Atlantia.pdf', ['mime' => 'application/pdf']);
    }
}
