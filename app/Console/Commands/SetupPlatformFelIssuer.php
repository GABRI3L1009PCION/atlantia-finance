<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Fel\FelIssuerSettings;
use App\Services\FiscalConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupPlatformFelIssuer extends Command
{
    protected $signature = 'fel:setup-platform {owner : Correo del administrador existente de Atlantia} {--address= : Dirección fiscal del emisor}';

    protected $description = 'Prepara la empresa emisora de suscripciones en una organización separada de los clientes.';

    public function handle(FelIssuerSettings $issuers, AccountingService $accounting, FiscalConfigurationService $fiscal): int
    {
        if ($uuid = $issuers->platformCompanyUuid()) {
            $this->info('El emisor ya está configurado: '.route('accounting.fel.settings.edit', $uuid));

            return self::SUCCESS;
        }
        $owner = User::where('email', $this->argument('owner'))->whereNotNull('email_verified_at')->first();
        $address = trim((string) ($this->option('address') ?: config('fel.platform.address')));
        if (! $owner || $address === '' || strlen($address) > 255) {
            $this->error('Indica un usuario administrador existente y verificado, y una dirección fiscal de hasta 255 caracteres.');

            return self::FAILURE;
        }
        $logo = file_get_contents(config('fel.platform.logo'));
        if ($logo === false) {
            $this->error('No se pudo leer el logo de Atlantia.');

            return self::FAILURE;
        }
        $uuid = DB::transaction(function () use ($owner, $address, $accounting, $fiscal, $logo): string {
            $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'professional'))
                ->where('billing_period', 'monthly')->where('is_active', true)->firstOrFail();
            $organization = Organization::create(['name' => 'Atlantia Systems · Administración', 'legal_name' => config('fel.platform.name'),
                'tax_identifier' => config('fel.platform.nit'), 'status' => 'active', 'country_code' => 'GT', 'currency_code' => 'GTQ', 'timezone' => 'America/Guatemala']);
            $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            $office = $organization->offices()->create(['code' => 'ATLANTIA', 'name' => 'Administración de suscripciones',
                'address_line_1' => $address, 'email' => $owner->email, 'status' => 'active', 'is_primary' => true]);
            $office->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active', 'assigned_at' => now()]);
            $subscription = $organization->subscriptions()->create(['provider' => 'internal', 'status' => 'active']);
            $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);
            $uuid = (string) Str::uuid();
            $id = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id,
                'name' => config('fel.platform.name'), 'tax_id' => config('fel.platform.nit'), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('company_fel_settings')->insert(['company_id' => $id, 'legal_name' => config('fel.platform.name'),
                'nit' => config('fel.platform.nit'), 'address' => $address, 'provider' => 'mock', 'environment' => 'test',
                'logo_data_uri' => 'data:image/png;base64,'.base64_encode($logo),
                'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_fel_issuer')->insert(['id' => 1, 'company_id' => $id]);
            $accounting->installDefaultChart($id);
            $fiscal->ensureDefaults($id, $owner->id);
            $organization = $owner->organizations()->whereKey($organization->id)->firstOrFail();
            $accounting->audit($organization, $owner, 'fel.platform.configured', 'companies', $id);

            return $uuid;
        });
        $this->info('Emisor de suscripciones: '.route('accounting.fel.settings.edit', $uuid));

        return self::SUCCESS;
    }
}
