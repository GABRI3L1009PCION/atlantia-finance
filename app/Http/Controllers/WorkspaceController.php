<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\ExecutiveDashboardService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function show(Request $request, WorkspaceContext $context, AccountingService $accounting, ExecutiveDashboardService $dashboard): Response|RedirectResponse
    {
        $organization = $context->organization($request->user());
        $owner = $organization->pivot->role === 'owner';
        $office = $organization->offices()->where('is_primary', true)->first();
        $price = $context->price($organization);
        $companyRows = $accounting->companies($organization, $request->user())
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.public_id', 'companies.name', 'companies.tax_id', 'offices.name as office_name']);
        $companies = $companyRows->map(fn (object $company): array => [
            'public_id' => $company->public_id,
            'name' => $company->name,
            'tax_id' => $company->tax_id,
            'office_name' => $company->office_name,
        ]);

        if (! $request->boolean('organization') && $companies->isNotEmpty()) {
            return to_route('accounting.companyDashboard', $companies->first()['public_id']);
        }

        return Inertia::render('dashboard', [
            'workspace' => [
                'name' => $organization->name,
                'phone' => $organization->phone,
                'canManage' => $owner,
                'needsSetup' => $office === null || $office->status !== 'active',
                'office' => $owner ? $office?->only(['name', 'email', 'phone', 'address_line_1', 'city']) : null,
                'offices' => $owner ? $organization->offices()->get(['name', 'city', 'status']) : $request->user()->offices()->where('organization_id', $organization->id)->wherePivot('status', 'active')->get(['name', 'city', 'offices.status']),
                'members' => $owner ? $organization->users()->wherePivot('status', 'active')->get()->map(fn ($user) => ['name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role]) : [],
                'invitations' => $owner ? $organization->invitations()->where('status', 'pending')->where('expires_at', '>', now())->get(['public_id', 'email', 'expires_at']) : [],
                'companies' => $companies,
            ],
            'catalog' => $owner && $price ? [
                'code' => $price->plan->code,
                'name' => $price->plan->name,
                'amount' => $price->amount,
                'currency' => $price->currency_code,
                'period' => $price->billing_period,
                'limits' => $price->plan->limits->pluck('limit_value', 'metric'),
                'features' => $price->plan->features()->orderBy('name')->get(['code', 'name']),
            ] : null,
            'metrics' => $dashboard->organization($companyRows->pluck('id')),
            'notice' => $request->session()->get('workspace_notice'),
        ]);
    }

    /** Updates organizations and its primary office only, never payment or plan fields. */
    public function update(Request $request, WorkspaceContext $context): RedirectResponse
    {
        $organization = $context->organization($request->user(), true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'office_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
        ], ['required' => 'Completa este campo.', 'email.email' => 'Escribe un correo válido.', 'max' => 'Este campo excede la longitud permitida.']);

        DB::transaction(function () use ($organization, $context, $data, $request): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $office = $organization->offices()->where('is_primary', true)->first();
            if ($office === null && $organization->offices()->count() >= $context->limit($organization, 'offices')) {
                throw ValidationException::withMessages(['office_name' => 'Tu plan no tiene cupos de oficinas disponibles.']);
            }
            $organization->update(['name' => $data['name'], 'phone' => $data['phone'] ?? null]);
            $values = ['name' => $data['office_name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'address_line_1' => $data['address'], 'city' => $data['city'], 'status' => 'active'];
            if ($office) {
                $office->update($values);
            } else {
                $office = $organization->offices()->create([...$values, 'code' => 'MAIN', 'is_primary' => true]);
            }
            $office->users()->syncWithoutDetaching([$request->user()->id => ['role' => 'owner', 'status' => 'active', 'assigned_at' => now()]]);
        });

        return to_route('dashboard')->with('workspace_notice', 'Tu oficina está lista. Bienvenido a Atlantia Finance.');
    }
}
