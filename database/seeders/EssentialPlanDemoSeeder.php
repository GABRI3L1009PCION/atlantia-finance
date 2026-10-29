<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Only the credentials are demo data: the organization is a real Esencial subscription (1 office, 2 seats) and every
 * client, period, task and obligation is created through the same services the screens use.
 */
class EssentialPlanDemoSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction();

        $this->call(PlanCatalogSeeder::class);

        $owner = $this->user('esencial.propietario@atlantia.test', 'Lucrecia Ramírez');
        $collaborator = $this->user('esencial.colaborador@atlantia.test', 'Pablo Girón');

        $organization = Organization::query()->withTrashed()->firstOrNew(['country_code' => 'GT', 'tax_identifier' => '3344556-7']);
        $organization->fill(['name' => 'Contabilidad Ramírez', 'legal_name' => 'Lucrecia Ramírez, Contadora Independiente', 'currency_code' => 'GTQ',
            'timezone' => 'America/Guatemala', 'status' => 'active']);
        $organization->deleted_at = null;
        $organization->save();

        foreach ([[$owner, 'owner', 'manager'], [$collaborator, 'member', 'member']] as [$user, $organizationRole, $officeRole]) {
            DB::table('organization_user')->updateOrInsert(['organization_id' => $organization->id, 'user_id' => $user->id],
                ['role' => $organizationRole, 'status' => 'active', 'joined_at' => now(), 'invited_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $office = DB::table('offices')->where('organization_id', $organization->id)->where('is_primary', true)->first();
        $officeId = $office->id ?? DB::table('offices')->insertGetId(['organization_id' => $organization->id, 'public_id' => (string) Str::uuid(),
            'code' => 'RAM-01', 'name' => 'Oficina Central', 'email' => $owner->email, 'city' => 'Guatemala', 'state' => 'Guatemala', 'country_code' => 'GT',
            'timezone' => 'America/Guatemala', 'is_primary' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$owner, 'manager'], [$collaborator, 'member']] as [$user, $officeRole]) {
            DB::table('office_user')->updateOrInsert(['office_id' => $officeId, 'user_id' => $user->id],
                ['role' => $officeRole, 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->subscription($organization);
        app(WorkAuthorizationService::class)->ensureDefaults($organization);
        $this->assignRole($organization->id, $officeId, $owner->id, $owner->id, 'owner_director');
        $this->assignRole($organization->id, $officeId, $owner->id, $collaborator->id, 'accountant_in_charge');

        $ownerInOrganization = $owner->organizations()->whereKey($organization->id)->firstOrFail();
        $companies = app(CompanyAdministrationService::class);
        $created = [];
        foreach ([['Panadería La Esperanza', '4455667-8', $collaborator->id], ['Ferretería El Tornillo', '7788990-1', $collaborator->id], ['Clínica Dental Sonrisas', '1239876-5', null], ['Arquitectura Línea Recta', '5512347-0', $collaborator->id]] as [$name, $taxId, $accountantId]) {
            $existing = DB::table('companies')->where('organization_id', $organization->id)->where('tax_id', $taxId)->value('id');
            if ($existing !== null) {
                $created[] = (int) $existing;

                continue;
            }
            $company = $companies->create($ownerInOrganization, $owner, ['name' => $name, 'tax_id' => $taxId, 'office_id' => $officeId, 'accountant_id' => $accountantId]);
            $created[] = $company['id'];
            // These are clients the practice already had: their past obligations (some overdue) belong to the demo.
            DB::table('companies')->where('id', $company['id'])->update(['created_at' => now()->subMonths(6)]);
            $this->openPeriods($company['id']);
        }

        app(FiscalObligationGenerationService::class)->generate($ownerInOrganization, $owner, $created);
        $this->tasks($ownerInOrganization, $owner, $collaborator, $officeId, $created);
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => config('demo.password')]);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $user;
    }

    private function subscription(Organization $organization): void
    {
        $priceId = (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('plans.code', 'essential')->where('plan_prices.billing_period', 'monthly')->value('plan_prices.id');
        $subscription = Subscription::query()->firstOrNew(['organization_id' => $organization->id, 'provider' => 'manual', 'provider_subscription_id' => 'demo-essential-'.$organization->id]);
        $subscription->fill(['provider_customer_id' => 'demo-essential-'.$organization->id, 'status' => 'active', 'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->endOfMonth(), 'cancel_at_period_end' => false])->save();
        SubscriptionItem::query()->updateOrCreate(['subscription_id' => $subscription->id, 'item_type' => 'base_plan'],
            ['plan_price_id' => $priceId, 'quantity' => 1, 'provider_item_id' => 'demo-essential-item-'.$organization->id]);
    }

    private function openPeriods(int $companyId): void
    {
        $anchor = CarbonImmutable::now()->startOfMonth();
        foreach (range(0, 2) as $offset) {
            $start = $anchor->subMonths($offset);
            DB::table('accounting_periods')->updateOrInsert(['company_id' => $companyId, 'starts_on' => $start->toDateString(), 'ends_on' => $start->endOfMonth()->toDateString()],
                ['name' => 'Período '.$start->format('Y-m'), 'closed_at' => null, 'closed_by' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function assignRole(int $organizationId, int $officeId, int $actorId, int $userId, string $roleCode): void
    {
        $roleId = (int) DB::table('workspace_roles')->where('organization_id', $organizationId)->where('code', $roleCode)->whereNull('deleted_at')->value('id');
        DB::table('user_role_assignments')->where('organization_id', $organizationId)->where('user_id', $userId)->where('status', 'active')
            ->update(['status' => 'inactive', 'ends_at' => now(), 'updated_at' => now()]);
        DB::table('user_role_assignments')->updateOrInsert(['organization_id' => $organizationId, 'user_id' => $userId, 'workspace_role_id' => $roleId],
            ['office_id' => $roleCode === 'owner_director' ? null : $officeId, 'assigned_by' => $actorId, 'status' => 'active', 'starts_at' => now(), 'ends_at' => null,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null]);
    }

    /** @param  list<int>  $companyIds */
    private function tasks(Organization $organization, User $owner, User $collaborator, int $officeId, array $companyIds): void
    {
        $definitions = [
            [0, 'accounting', 'classify_documents', 'Clasificar facturas del mes', $collaborator->id, 'high', 2],
            [1, 'accounting', 'review_entry', 'Registrar compras pendientes', $collaborator->id, 'normal', 4],
            [2, 'tax', 'prepare_vat_books', 'Preparar libros IVA del mes', $owner->id, 'high', 3],
        ];
        foreach ($definitions as [$index, $module, $type, $title, $assignee, $priority, $days]) {
            app(WorkTaskService::class)->create($organization, $owner, ['office_id' => $officeId, 'company_id' => $companyIds[$index], 'module' => $module, 'task_type' => $type,
                'title' => $title, 'assigned_to' => $assignee, 'priority' => $priority, 'due_at' => now()->addDays($days), 'automation_key' => 'essential-start:'.$companyIds[$index].':'.$type]);
        }
    }
}
