<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Local-only team of the Multi-oficina demo firm: 24 collaborators (plus the owner = the plan's 25 users) covering every
 * role the plan allows. They are created already accepted, with the shared demo password, and nothing is e-mailed.
 */
class MultiOfficeTeamDemoSeeder extends Seeder
{
    private const OWNER_EMAIL = 'contadoresyconsultoresdeizabal@gmail.com';

    /** @var array<string, array{name: string, city: string}> */
    private const OFFICES = [
        'PRINCIPAL' => ['name' => 'Contadores y consultores de Izabal', 'city' => 'Puerto Barrios'],
        'MORALES' => ['name' => 'Oficina Morales', 'city' => 'Morales'],
        'LIVINGSTON' => ['name' => 'Oficina Livingston', 'city' => 'Livingston'],
        'ESTOR' => ['name' => 'Oficina El Estor', 'city' => 'El Estor'],
        'AMATES' => ['name' => 'Oficina Los Amates', 'city' => 'Los Amates'],
    ];

    /** @var list<array{string, string, string, string}> office code, name, e-mail, role code */
    private const TEAM = [
        ['PRINCIPAL', 'Sandra Elizabeth Morales Choc', 'sandra.morales@izabal.example', 'office_admin'],
        ['PRINCIPAL', 'Carlos Enrique Pérez Ajanel', 'carlos.perez@izabal.example', 'accountant_in_charge'],
        ['PRINCIPAL', 'Wendy Marisol López Caal', 'wendy.lopez@izabal.example', 'accounting_assistant'],
        ['PRINCIPAL', 'Jorge Mario Estrada Cú', 'jorge.estrada@izabal.example', 'accounting_supervisor'],
        ['PRINCIPAL', 'Karla Patricia Ramírez Tzul', 'karla.ramirez@izabal.example', 'tax_specialist'],
        ['PRINCIPAL', 'Luis Fernando Coc Xol', 'luis.coc@izabal.example', 'treasury'],
        ['PRINCIPAL', 'Hilda Noemí Barrios Pop', 'hilda.barrios@izabal.example', 'auditor'],
        ['MORALES', 'Edgar Rolando García Quej', 'edgar.garcia@izabal.example', 'office_admin'],
        ['MORALES', 'María José Hernández Cac', 'maria.hernandez@izabal.example', 'accountant_in_charge'],
        ['MORALES', 'Brenda Lucía Archila Tun', 'brenda.archila@izabal.example', 'accounting_assistant'],
        ['MORALES', 'Oscar René Ical Maquín', 'oscar.ical@izabal.example', 'accounts_receivable'],
        ['LIVINGSTON', 'Rosa Amelia Castillo Bol', 'rosa.castillo@izabal.example', 'office_admin'],
        ['LIVINGSTON', 'Byron Alexander Mejía Chub', 'byron.mejia@izabal.example', 'accountant_in_charge'],
        ['LIVINGSTON', 'Ingrid Yesenia Sagastume Ba', 'ingrid.sagastume@izabal.example', 'accounting_assistant'],
        ['LIVINGSTON', 'Walter Estuardo Cuc Rax', 'walter.cuc@izabal.example', 'accounts_payable'],
        ['ESTOR', 'Miriam Beatriz Pacay Ical', 'miriam.pacay@izabal.example', 'office_admin'],
        ['ESTOR', 'Julio César Chen Tiul', 'julio.chen@izabal.example', 'accountant_in_charge'],
        ['ESTOR', 'Anabella Jacqueline Mó Cucul', 'anabella.mo@izabal.example', 'accounting_assistant'],
        ['ESTOR', 'Rodolfo Antonio Sierra Pop', 'rodolfo.sierra@izabal.example', 'tax_specialist'],
        ['ESTOR', 'Elda Guisela Tot Caal', 'elda.tot@izabal.example', 'accounting_supervisor'],
        ['AMATES', 'Héctor Manuel Orellana Vásquez', 'hector.orellana@izabal.example', 'office_admin'],
        ['AMATES', 'Dina Maribel Ordóñez Cano', 'dina.ordonez@izabal.example', 'accountant_in_charge'],
        ['AMATES', 'Josué David Monroy Lemus', 'josue.monroy@izabal.example', 'accounting_assistant'],
        ['AMATES', 'Silvia Karina Paiz Gómez', 'silvia.paiz@izabal.example', 'accounting_assistant'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Este equipo de prueba solo se crea en local.');
        }
        $this->call(MultiOfficePlanDemoSeeder::class);

        $owner = User::query()->where('email', self::OWNER_EMAIL)->firstOrFail();
        $organization = $owner->organizations()->where('organizations.id', $owner->current_organization_id)->firstOrFail();
        $authorization = app(WorkAuthorizationService::class);

        Notification::fake();
        $offices = $this->offices($organization);

        foreach (self::TEAM as [$code, $name, $email, $role]) {
            $officeId = $offices[$code];
            DB::transaction(function () use ($organization, $owner, $authorization, $officeId, $name, $email, $role): void {
                $user = User::query()->where('email', $email)->first() ?? User::query()->create(['name' => $name, 'email' => $email, 'password' => config('demo.password')]);
                $user->markEmailAsVerified();
                $user->forceFill(['current_organization_id' => $organization->id])->save();
                DB::table('organization_user')->updateOrInsert(['organization_id' => $organization->id, 'user_id' => $user->id],
                    ['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'invited_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('office_user')->updateOrInsert(['office_id' => $officeId, 'user_id' => $user->id],
                    ['role' => $role === 'office_admin' ? 'admin' : 'member', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $hasRole = DB::table('user_role_assignments')->where('organization_id', $organization->id)->where('user_id', $user->id)->where('office_id', $officeId)->where('status', 'active')->exists();
                if (! $hasRole) {
                    $authorization->assignRole($organization, $owner, $user->id, $role, $officeId);
                }
            });
        }
    }

    /**
     * @return array<string, int> office id by code, creating the ones that do not exist yet
     */
    private function offices(Organization $organization): array
    {
        $ids = [];
        foreach (self::OFFICES as $code => $office) {
            $id = DB::table('offices')->where('organization_id', $organization->id)->where('code', $code)->whereNull('deleted_at')->value('id');
            if ($id === null) {
                $id = DB::table('offices')->insertGetId(['organization_id' => $organization->id, 'code' => $code, 'name' => $office['name'], 'city' => $office['city'], 'country_code' => 'GT', 'timezone' => 'America/Guatemala', 'status' => 'active', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
            $ids[$code] = (int) $id;
        }

        return $ids;
    }
}
