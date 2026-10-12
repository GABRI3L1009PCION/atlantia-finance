<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo commercial data (pipeline, service contracts, interactions) for the firm of the demo director.
 * Safe to run twice: it does nothing when the organization already has commercial data.
 */
class CrmDemoSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = (int) DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('users.email', 'socio.director@atlantia.test')
            ->value('organization_user.organization_id');

        if ($organizationId === 0 || DB::table('crm_prospects')->where('organization_id', $organizationId)->exists()) {
            return;
        }

        $now = now();
        $staff = DB::table('organization_user')->where('organization_id', $organizationId)->where('status', 'active')->orderBy('user_id')->pluck('user_id')->all();
        $companies = DB::table('companies')->where('organization_id', $organizationId)->orderBy('id')->pluck('id')->all();
        $services = ['Contabilidad + Impuestos', 'Contabilidad + Nómina', 'Contabilidad', 'Contabilidad + Impuestos', 'Contabilidad + Nómina', 'Contabilidad'];
        $fees = [4500, 6200, 3000, 5200, 5800, 2800];
        $statuses = ['active', 'active', 'onboarding', 'active', 'active', 'onboarding'];

        foreach ($companies as $index => $companyId) {
            DB::table('crm_contracts')->insert([
                'organization_id' => $organizationId,
                'company_id' => $companyId,
                'services' => $services[$index % 6],
                'monthly_fee_cents' => $fees[$index % 6] * 100,
                'starts_on' => $now->copy()->subMonths(8 - $index)->toDateString(),
                'renews_on' => $now->copy()->addDays(20 + $index * 35)->toDateString(),
                'status' => $statuses[$index % 6],
                'owner_id' => $staff[$index % max(1, count($staff))] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pipeline = [
            ['Agroexportadora del Caribe, S.A.', 'prospect', 12000, 25], ['Constructora Delta', 'prospect', 8000, 40], ['Hoteles del Lago', 'contact', 9500, 20],
            ['Importadora Maya', 'contact', 6000, 30], ['Servicios Profesionales GT', 'proposal', 7500, 14], ['Textiles de Guatemala', 'negotiation', 11000, 10],
            ['Logística del Norte', 'won', 9000, -5],
        ];

        $prospectIds = [];
        foreach ($pipeline as $index => [$name, $stage, $value, $days]) {
            $prospectIds[] = DB::table('crm_prospects')->insertGetId([
                'organization_id' => $organizationId,
                'name' => $name,
                'contact_name' => 'Contacto '.($index + 1),
                'stage' => $stage,
                'estimated_value_cents' => $value * 100,
                'expected_close_on' => $now->copy()->addDays($days)->toDateString(),
                'owner_id' => $staff[$index % max(1, count($staff))] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            ['Reunión con el cliente para revisar resultados de agosto y plan fiscal.', 'meeting', 0, null],
            ['Correo enviado solicitando documentos de nómina.', 'email', 1, null],
            ['Llamada sobre obligaciones fiscales atrasadas.', 'call', 2, null],
            ['Reunión de onboarding y revisión del checklist de documentación.', 'meeting', null, 0],
        ] as $index => [$summary, $kind, $companyIndex, $prospectIndex]) {
            DB::table('crm_interactions')->insert([
                'organization_id' => $organizationId,
                'company_id' => $companyIndex !== null ? ($companies[$companyIndex] ?? null) : null,
                'crm_prospect_id' => $prospectIndex !== null ? $prospectIds[$prospectIndex] : null,
                'user_id' => $staff[$index % max(1, count($staff))] ?? null,
                'kind' => $kind,
                'summary' => $summary,
                'occurred_at' => $now->copy()->subDays($index + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
