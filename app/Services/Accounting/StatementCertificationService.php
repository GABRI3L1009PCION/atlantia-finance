<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Fel\FelPdfService;
use App\Services\FiscalConfigurationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The certification Guatemalan accountants print under the financial statements and the Libro de Inventarios: "El
 * infrascrito Perito Contador, registrado en la SAT con el número…, CERTIFICA…", with the key figure in words, the
 * place and date, and the signature lines of the owner or legal representative and of the accountant.
 *
 * @phpstan-type Signers array{signer_name: string, signer_title: string, accountant_name: string, accountant_title: string, accountant_registration: string, signing_place: string}
 * @phpstan-type Certification array{text: string, place_date: string, signatures: list<array{name: string, title: string}>}
 */
class StatementCertificationService
{
    public const ACCOUNTANT_TITLES = ['Perito Contador', 'Contador Público y Auditor'];

    public const SIGNER_TITLES = ['Propietario', 'Representante legal'];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private FiscalConfigurationService $fiscal, private AccountingService $accounting) {}

    /** @return Signers */
    public function signers(int $companyId): array
    {
        $profile = $this->fiscal->ensureDefaults($companyId);
        $representative = (string) (DB::table('companies')->where('id', $companyId)->value('legal_representative') ?? '');

        return ['signer_name' => (string) ($profile->signer_name ?? '') ?: $representative, 'signer_title' => (string) ($profile->signer_title ?? '') ?: 'Representante legal',
            'accountant_name' => (string) ($profile->accountant_name ?? ''), 'accountant_title' => (string) ($profile->accountant_title ?? '') ?: 'Perito Contador',
            'accountant_registration' => (string) ($profile->accountant_registration ?? ''), 'signing_place' => (string) ($profile->signing_place ?? '')];
    }

    /** @param Signers $data */
    public function save(Organization $organization, User $user, int $companyId, array $data): void
    {
        $this->fiscal->ensureDefaults($companyId);
        $clean = array_map(fn (string $value): ?string => trim($value) === '' ? null : trim($value), $data);
        DB::table('company_fiscal_profiles')->where('company_id', $companyId)->update([...$clean, 'updated_by' => $user->id, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'statements.signers.updated', 'companies', $companyId, ['accountant_registration' => $clean['accountant_registration']]);
    }

    /**
     * Certification of one document of a company. $figure is the amount the accountant certifies (total assets for the
     * balance sheet and the inventory, the result of the period for the income statement).
     *
     * @return Certification
     */
    public function certify(stdClass $company, string $document, string $from, string $to, int $figure, string $figureLabel): array
    {
        $signers = $this->signers((int) $company->id);
        $accountant = $signers['accountant_name'] === '' ? '_______________________' : $signers['accountant_name'];
        $registration = $signers['accountant_registration'] === '' ? '__________' : $signers['accountant_registration'];
        $taxId = (string) ($company->tax_id ?? '');
        $period = $from === $to ? 'al '.$this->longDate($to) : 'del '.$this->longDate($from).' al '.$this->longDate($to);
        $text = 'El infrascrito '.$signers['accountant_title'].', '.$accountant.', registrado en la Superintendencia de Administración Tributaria con el número '.$registration
            .', CERTIFICA: que el '.$document.' de '.$company->name.($taxId === '' ? '' : ', NIT '.$taxId).', '.$period
            .', fue elaborado con base en los registros contables de la empresa y presenta razonablemente su situación, de acuerdo con las normas de contabilidad aplicables, con '
            .$figureLabel.' de '.$this->words($figure).' (Q'.number_format(abs($figure) / 100, 2).($figure < 0 ? ' negativos' : '').').';

        return ['text' => $text, 'place_date' => ($signers['signing_place'] === '' ? '' : $signers['signing_place'].', ').$this->longDate($to).'.',
            'signatures' => [['name' => $signers['signer_name'], 'title' => $signers['signer_title']],
                ['name' => $signers['accountant_name'], 'title' => $signers['accountant_title'].($signers['accountant_registration'] === '' ? '' : ' · Registro SAT '.$signers['accountant_registration'])]]];
    }

    /** The amount in words as Guatemalan certifications write it: "DOSCIENTOS VEINTINUEVE MIL … QUETZALES CON 57/100". */
    public function words(int $cents): string
    {
        return FelPdfService::amountInWords(abs($cents));
    }

    private function longDate(string $date): string
    {
        $day = Carbon::parse($date);

        return $day->day.' de '.self::MONTHS[$day->month - 1].' de '.$day->year;
    }
}
