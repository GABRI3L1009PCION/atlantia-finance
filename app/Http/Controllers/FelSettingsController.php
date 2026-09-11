<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\Fel\FelIssuerSettings;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FelSettingsController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting, private FelIssuerSettings $issuers) {}

    public function edit(Request $request, string $company): Response
    {
        $organization = $this->context->organization($request->user(), true);
        $this->context->requireFeature($organization, 'basic_invoicing');
        $record = $this->accounting->company($organization, $request->user(), $company, true);

        return Inertia::render('accounting/fel/settings', [
            'company' => $record, 'issuer' => $this->issuers->forCompany($record),
            'isPlatform' => $company === $this->issuers->platformCompanyUuid(),
            'notice' => session('accounting_notice'),
        ]);
    }

    public function update(Request $request, string $company): RedirectResponse
    {
        $organization = $this->context->organization($request->user(), true);
        $this->context->requireFeature($organization, 'basic_invoicing');
        $record = $this->accounting->company($organization, $request->user(), $company, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'nit' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/D'],
            'address' => ['required', 'string', 'max:255'], 'provider' => ['required', 'in:mock'], 'environment' => ['required', 'in:test'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:512', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            $current = $this->issuers->forCompany($record);
            $logo = ($data['remove_logo'] ?? false) ? null : $current['logo_data_uri'];
            if ($file = $request->file('logo')) {
                $logo = 'data:'.$file->getMimeType().';base64,'.base64_encode($file->getContent());
            }
            DB::table('company_fel_settings')->updateOrInsert(['company_id' => $record->id], [
                'legal_name' => $data['name'], 'nit' => strtoupper($data['nit']), 'address' => $data['address'],
                'provider' => $data['provider'], 'environment' => $data['environment'], 'logo_data_uri' => $logo,
                'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $request->user(), 'fel.settings.updated', 'company_fel_settings', $record->id,
                ['provider' => $data['provider'], 'environment' => $data['environment']]);
        });

        return back()->with('accounting_notice', 'Datos del emisor guardados. Se usarán en las próximas facturas de esta empresa.');
    }
}
