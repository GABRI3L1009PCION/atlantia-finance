<?php

declare(strict_types=1);

namespace App\Services\Fel;

use Illuminate\Validation\ValidationException;

enum FelDocumentStatus: string
{
    case Draft = 'draft';
    case AwaitingSat = 'awaiting_sat';
    case Pending = 'pending';
    case Processing = 'processing';
    case Certified = 'certified';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function transitionTo(self $next): void
    {
        $allowed = match ($this) {
            self::Draft => [self::Pending, self::AwaitingSat],
            self::Failed => [self::Pending],
            // A document prepared to be issued by hand in the SAT portal is registered once the SAT issued it, or goes back to a draft.
            self::AwaitingSat => [self::Certified, self::Draft],
            self::Pending => [self::Processing],
            self::Processing => [self::Certified, self::Failed],
            self::Certified => [self::Cancelled],
            self::Cancelled => [],
        };
        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages(['document' => 'La operación no es válida para el estado del documento.']);
        }
    }
}
