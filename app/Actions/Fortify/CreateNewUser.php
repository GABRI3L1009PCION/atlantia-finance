<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Office;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\LegalDocumentService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'plan' => [
                'required',
                'string',
                Rule::exists('plans', 'code')
                    ->where('is_active', true),
            ],
            'billing_period' => [
                'required',
                'string',
                Rule::in(['monthly', 'annual']),
            ],
            'organization_name' => ['required', 'string', 'min:3', 'max:150'],
            'terms' => ['accepted'],
        ])->validate();

        if (in_array($input['plan'], (array) config('atlantia-plans.contact_only', []), true)) {
            throw ValidationException::withMessages(['plan' => 'El plan Empresarial se cotiza a la medida: escríbenos por WhatsApp o a soporte@atlantiasystems.com.']);
        }

        $planPrice = PlanPrice::query()
            ->with('plan')
            ->where('billing_period', $input['billing_period'])
            ->where('currency_code', 'GTQ')
            ->where('is_active', true)
            ->whereHas('plan', function ($query) use ($input): void {
                $query
                    ->where('code', $input['plan'])
                    ->where('is_active', true);
            })
            ->first();

        if ($planPrice === null) {
            throw ValidationException::withMessages([
                'plan' => 'El plan o periodo seleccionado no está disponible.',
            ]);
        }

        // A brand-new organization has no tenant scope yet, so onboarding runs with platform access.
        $user = app(TenantDatabaseContext::class)->runAsSystem(fn (): User => DB::transaction(
            function () use ($input, $planPrice): User {
                $user = User::query()->create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);

                $organization = Organization::query()->create([
                    'name' => trim((string) $input['organization_name']),
                    'country_code' => 'GT',
                    'currency_code' => 'GTQ',
                    'timezone' => 'America/Guatemala',
                    'status' => 'pending_email_verification',
                ]);

                $organization->users()->attach($user->id, [
                    'role' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                    'invited_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $office = Office::query()->create([
                    'organization_id' => $organization->id,
                    'code' => 'MAIN',
                    // A firm starts with one office, named like the firm itself.
                    'name' => $organization->name,
                    'email' => $user->email,
                    'country_code' => 'GT',
                    'timezone' => 'America/Guatemala',
                    'is_primary' => true,
                    'status' => 'pending',
                ]);

                $office->users()->attach($user->id, [
                    'role' => 'owner',
                    'status' => 'active',
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $subscription = Subscription::query()->create([
                    'organization_id' => $organization->id,
                    'status' => 'incomplete',
                    'cancel_at_period_end' => false,
                ]);

                $subscription->items()->create([
                    'plan_price_id' => $planPrice->id,
                    'item_type' => 'base_plan',
                    'quantity' => 1,
                ]);

                return $user;
            },
            attempts: 3,
        ));

        app(LegalDocumentService::class)->acceptCurrent($user, 'register', request()->ip(), request()->userAgent());

        request()->session()->put('onboarding.selected_plan', [
            'code' => $planPrice->plan->code,
            'billing_period' => $planPrice->billing_period,
        ]);

        return $user;
    }
}
