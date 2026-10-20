<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use stdClass;

/**
 * How an approved obligation is presented. Atlantia has no connection to the SAT, so the only provider is the external
 * one: the person presents in the SAT portal and records the presentation with its evidence. A real provider (a certifier
 * or an official service) would implement `file()`, return the provider reference, and the workflow would store the
 * response and keep the call idempotent; nothing in the flow pretends that Atlantia filed something it did not.
 */
interface TaxFilingProviderInterface
{
    /** Whether Atlantia itself can send the filing (false for the external, manual provider). */
    public function supportsDirectFiling(): bool;

    /** Whether closing the obligation requires an evidence file (receipt, acknowledgement). */
    public function evidenceRequired(stdClass $obligation): bool;

    /**
     * Sends the filing and returns what the provider answered.
     *
     * @param  array<string, mixed>  $data
     * @return array{reference: string, submitted_at: string, response: array<string, mixed>}
     */
    public function file(stdClass $obligation, array $data): array;
}
