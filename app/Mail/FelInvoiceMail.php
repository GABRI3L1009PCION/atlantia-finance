<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\FelDocument;
use Illuminate\Mail\Mailable;

class FelInvoiceMail extends Mailable
{
    public function __construct(public FelDocument $document, private string $pdf) {}

    public static function subjectFor(FelDocument $document): string
    {
        return $document->provider === 'mock'
            ? 'PRUEBA FEL · '.$document->series.'-'.$document->number
            : 'Factura electrónica · '.$document->fel_series.'-'.$document->fel_number;
    }

    public static function attachmentName(FelDocument $document): string
    {
        return $document->provider === 'mock' ? 'FEL-PRUEBA-'.$document->number.'.pdf' : 'FEL-'.$document->fel_series.'-'.$document->fel_number.'.pdf';
    }

    public function build(): static
    {
        return $this->subject(self::subjectFor($this->document))
            ->view('fel.email')->attachData($this->pdf, self::attachmentName($this->document), ['mime' => 'application/pdf']);
    }
}
