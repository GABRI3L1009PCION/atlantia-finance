<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Fel\FelCertifiers;
use App\Services\Fel\FelIssuerSettings;
use App\Services\Fel\FelProviderResolver;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class FelSettingsController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting, private FelIssuerSettings $issuers, private WorkAuthorizationService $authorization) {}

    public function edit(Request $request, string $company): Response
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'basic_invoicing');
        $record = $this->resolveCompany($request, $organization, $company, false);

        return Inertia::render('accounting/fel/settings', [
            'company' => $record, 'issuer' => $this->issuers->forCompany($record), 'certification' => $this->issuers->certifierFor($record),
            'certifiers' => collect(FelCertifiers::CERTIFIERS)->map(fn (array $definition, string $code): array => ['code' => $code, ...$definition])->values()->all(),
            'vatAffiliations' => FelCertifiers::VAT_AFFILIATIONS,
            'isPlatform' => $company === $this->issuers->platformCompanyUuid(),
        ]);
    }

    public function update(Request $request, string $company): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'basic_invoicing');
        $record = $this->resolveCompany($request, $organization, $company, true);
        $existing = DB::table('company_fel_settings')->where('company_id', $record->id)->first();
        $request->mergeIfMissing([
            'certifier' => $existing->certifier ?? FelCertifiers::NONE,
            'certifier_environment' => $existing->certifier_environment ?? 'test',
            'establishment_code' => $existing->establishment_code ?? '1',
            'vat_affiliation' => $existing->vat_affiliation ?? 'GEN',
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'nit' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/D'],
            'address' => ['required', 'string', 'max:255'], 'provider' => ['sometimes', 'in:mock,sat_manual'], 'environment' => ['sometimes', 'in:test,production'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:512', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['sometimes', 'boolean'],
            'certifier' => ['required', Rule::in(FelCertifiers::codes())],
            'certifier_environment' => ['required', Rule::in(['test', 'production'])],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
            'establishment_code' => ['required', 'regex:/^[0-9]{1,10}$/D'],
            'vat_affiliation' => ['required', Rule::in(array_keys(FelCertifiers::VAT_AFFILIATIONS))],
            'issuer_email' => ['nullable', 'email', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
        ]);
        // Issuing by hand in the SAT portal is its own mode; every other certifier stays simulated until its adapter is validated.
        $data['provider'] = $data['certifier'] === FelCertifiers::SAT_MANUAL ? FelProviderResolver::SAT_MANUAL : 'mock';
        $data['environment'] = 'test';
        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            $current = $this->issuers->forCompany($record);
            $stored = DB::table('company_fel_settings')->where('company_id', $record->id)->value('certifier_credentials');
            $sealed = app(FelCertifiers::class)->seal($data['certifier'], $data['credentials'] ?? [], $data['certifier'] === DB::table('company_fel_settings')->where('company_id', $record->id)->value('certifier') ? $stored : null);
            $logo = ($data['remove_logo'] ?? false) ? null : $current['logo_data_uri'];
            if ($file = $request->file('logo')) {
                $logo = 'data:'.$file->getMimeType().';base64,'.base64_encode($file->getContent());
            }
            DB::table('company_fel_settings')->updateOrInsert(['company_id' => $record->id], [
                'legal_name' => $data['name'], 'nit' => strtoupper($data['nit']), 'address' => $data['address'],
                'provider' => $data['provider'], 'environment' => $data['environment'], 'logo_data_uri' => $logo,
                'certifier' => $data['certifier'], 'certifier_environment' => $data['certifier_environment'], 'certifier_credentials' => $sealed, 'establishment_code' => $data['establishment_code'],
                'vat_affiliation' => $data['vat_affiliation'], 'issuer_email' => $data['issuer_email'] ?? null, 'trade_name' => $data['trade_name'] ?? null,
                'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $request->user(), 'fel.settings.updated', 'company_fel_settings', $record->id,
                ['provider' => $data['provider'], 'environment' => $data['environment'], 'certifier' => $data['certifier']]);
        });

        return back()->with('accounting_notice', 'Datos del emisor guardados. Se usarán en las próximas facturas de esta empresa.');
    }

    /**
     * Administrators of an office configure the issuer of every company in it, even without being assigned to the
     * company; everyone else needs access to the company and the FEL settings module as before.
     */
    private function resolveCompany(Request $request, Organization $organization, string $uuid, bool $write): stdClass
    {
        $user = $request->user();
        $company = DB::table('companies')->where('organization_id', $organization->id)->where('public_id', $uuid)->first();
        abort_if($company === null, 404);
        $offices = $this->authorization->officesWithPermission($organization, $user, 'fel.settings');

        if ($offices !== null && in_array((int) $company->office_id, $offices, true)) {
            return $company;
        }

        $record = $this->accounting->company($organization, $user, $uuid, $write);
        $this->accounting->assertModule($organization, $record->id, $user, 'fel.settings');

        return $record;
    }
}
