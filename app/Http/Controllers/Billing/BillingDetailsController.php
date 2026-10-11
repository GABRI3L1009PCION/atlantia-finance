<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BillingDetailsController extends Controller
{
    public function edit(Request $request): Response
    {
        $organization = $this->organization($request);
        $profile = DB::table('organization_billing_profiles')->where('organization_billing_profiles.organization_id', $organization->id)->first();

        return Inertia::render('billing/details', [
            'billing' => $profile ? (array) $profile : ['name' => $organization->legal_name ?? '', 'nit' => $organization->tax_identifier ?? '', 'address' => ''],
            'email' => $request->user()->email,
            'issuer' => ['name' => config('fel.platform.name'), 'nit' => config('fel.platform.nit')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'nit' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/D'],
            'address' => ['required', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($organization, $request, $data): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            if ($organization->payments()->where('status', 'pending')
                ->whereHas('attempts', fn ($q) => $q->where('status', 'created')->where('attempted_at', '>=', now()->subMinutes(30)))->exists()) {
                throw ValidationException::withMessages(['billing' => 'Hay un pago en curso. Sus datos fiscales ya están guardados; espera a que termine o expire para modificarlos.']);
            }
            DB::table('organization_billing_profiles')->updateOrInsert(['organization_id' => $organization->id], [
                ...$data, 'nit' => strtoupper($data['nit']), 'email' => $request->user()->email, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return to_route('billing.checkout.show');
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->organizations()->wherePivot('role', 'owner')->wherePivot('status', 'active')->latest('organizations.created_at')->firstOrFail();
    }
}
