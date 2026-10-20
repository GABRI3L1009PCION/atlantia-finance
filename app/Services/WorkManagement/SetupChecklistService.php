<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * The guided start of a new firm (the "initial training" of the plan): what is already set up and what is missing,
 * computed from the real records of the offices in scope. Nothing is marked by hand: a step is done when the data exists.
 *
 * @phpstan-type Step array{key: string, title: string, detail: string, done: bool, current: int, total: int, href: string}
 */
class SetupChecklistService
{
    public function __construct(private WorkspaceContext $context) {}

    /**
     * @param  list<int>  $officeIds
     * @return array{percent: int, done: int, total: int, steps: list<Step>}
     */
    public function payload(Organization $organization, array $officeIds): array
    {
        $ids = $officeIds === [] ? [-1] : $officeIds;
        $multi = $this->context->hasFeature($organization, 'multi_office');
        $offices = DB::table('offices')->where('offices.organization_id', $organization->id)->whereIn('id', $ids)->whereNull('deleted_at')->where('status', 'active')->get(['id', 'name']);
        $officeLimit = $this->context->limit($organization, 'offices');
        $companies = DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('office_id', $ids)->get(['id', 'office_id', 'public_id', 'name']);
        $companyIds = $companies->pluck('id')->all() ?: [-1];

        $withAdmin = DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')->where('ura.organization_id', $organization->id)
            ->where('ura.status', 'active')->whereNull('ura.deleted_at')->where('role.code', 'office_admin')->whereIn('ura.office_id', $ids)->distinct()->pluck('ura.office_id')->all();
        $members = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('status', 'active')->count();
        $pending = DB::table('invitations')->where('invitations.organization_id', $organization->id)->where('status', 'pending')->where('expires_at', '>', now())->count();
        $officesWithClients = $companies->pluck('office_id')->unique()->count();
        $withAccountant = DB::table('company_assignments')->whereIn('company_id', $companyIds)->where('assignment_role', 'accountant_in_charge')->where('status', 'active')->whereNull('deleted_at')->distinct()->count('company_id');
        $withIssuer = DB::table('company_fel_settings')->whereIn('company_id', $companyIds)->whereNotNull('certifier')->where('certifier', '!=', 'none')->count();
        $withCalendar = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $companyIds)->distinct()->count('company_id');
        $firstWithoutIssuer = $companies->first(fn (object $company): bool => ! DB::table('company_fel_settings')->where('company_id', $company->id)->whereNotNull('certifier')->where('certifier', '!=', 'none')->exists());

        $steps = [
            $this->step('offices', $multi ? 'Crea tus oficinas' : 'Configura tu oficina', $multi ? 'Cada sede tiene su equipo, sus clientes y su administrador. Tu plan permite '.$officeLimit.'.' : 'Tu oficina es la base del equipo y de los clientes.',
                $offices->count(), $multi ? min(2, $officeLimit) : 1, '/office/offices'),
            $this->step('admins', 'Nombra un administrador por oficina', 'Cada administrador ve y maneja solo su sede; tú ves todas.', $offices->count() === 0 ? 0 : count(array_intersect($offices->pluck('id')->all(), $withAdmin)), max(1, $offices->count()), '/office/users'),
            $this->step('team', 'Invita a tu equipo', 'Contadores, auxiliares y revisores reciben una invitación segura por correo.', min($members + $pending, 2), 2, '/office/invitations'),
            $this->step('clients', 'Registra las empresas que atiendes', 'Cada oficina necesita al menos un cliente para empezar a trabajar.', $officesWithClients, max(1, $offices->count()), '/office/companies'),
            $this->step('accountants', 'Asigna un contador encargado a cada empresa', 'Es quien responde por la contabilidad y revisa el trabajo.', $withAccountant, max(1, $companies->count()), '/office/assignments'),
            $this->step('invoicing', 'Configura cómo emite facturas cada empresa', 'Elige emitir en el portal de la SAT o con un certificador autorizado.', $withIssuer, max(1, $companies->count()),
                $firstWithoutIssuer === null ? '/accounting' : '/accounting/companies/'.$firstWithoutIssuer->public_id.'/fel/settings'),
            $this->step('calendar', 'Genera el calendario fiscal', 'IVA, ISR y retenciones con su fecha, responsable y revisor.', $withCalendar, max(1, $companies->count()), '/office/compliance'),
        ];
        $done = count(array_filter($steps, fn (array $step): bool => $step['done']));

        return ['percent' => (int) floor($done * 100 / count($steps)), 'done' => $done, 'total' => count($steps), 'steps' => $steps];
    }

    /** @return Step */
    private function step(string $key, string $title, string $detail, int $current, int $total, string $href): array
    {
        return ['key' => $key, 'title' => $title, 'detail' => $detail, 'done' => $current >= $total, 'current' => min($current, $total), 'total' => $total, 'href' => $href];
    }
}
