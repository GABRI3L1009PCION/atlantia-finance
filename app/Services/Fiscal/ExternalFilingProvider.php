<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use LogicException;
use stdClass;

/** Presentation done outside Atlantia (the SAT portal): it is recorded afterwards, never executed from here. */
class ExternalFilingProvider implements TaxFilingProviderInterface
{
    public function supportsDirectFiling(): bool
    {
        return false;
    }

    public function evidenceRequired(stdClass $obligation): bool
    {
        return true;
    }

    public function file(stdClass $obligation, array $data): array
    {
        throw new LogicException('Atlantia no presenta declaraciones ante la SAT: se presenta en su portal y se registra aquí la constancia.');
    }
}
