<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\FelDocument;
use App\Services\Notifications\ClientMailIdentityService;
use Illuminate\Mail\Mailable;

/**
 * Certified invoice e-mailed to the customer, under the issuing company's name; replying reaches the firm.
 *
 * @phpstan-import-type ClientMailIdentity from ClientMailIdentityService
 */
class FelInvoiceMail extends Mailable
{
    /**
     * @param  ClientMailIdentity|null  $identity
     */
    public function __construct(public FelDocument $document, private string $pdf, private ?array $identity = null) {}

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
        $mail = $this->subject(self::subjectFor($this->document))
            ->view('fel.email')->attachData($this->pdf, self::attachmentName($this->document), ['mime' => 'application/pdf']);
        if ($this->identity !== null) {
            $mail->from((string) config('mail.from.address'), $this->identity['from_name']);
            if ($this->identity['reply_to'] !== null) {
                $mail->replyTo($this->identity['reply_to'], $this->identity['from_name']);
            }
        }

        return $mail;
    }
}
