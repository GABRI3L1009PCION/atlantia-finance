<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FirmProfileController extends Controller
{
    private const TYPES = ['Sociedad Anónima', 'Sociedad de Responsabilidad Limitada', 'Comerciante individual', 'Persona individual', 'Otra'];

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private AccountingService $accounting,
    ) {}

    /** GET office/firm-profile: identity of the firm. Whoever holds office.settings.manage also edits it. */
    public function show(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->canView($organization, $request), 403);
        $profile = $this->profile($organization);

        return Inertia::render('work/firm-profile', [
            'profile' => [...(array) $profile, 'logo_url' => $profile->logo_path ? '/office/firm-profile/logo' : null],
            'offices' => DB::table('offices')->where('organization_id', $organization->id)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('name')
                ->get(['id', 'name', 'city', 'address_line_1', 'phone', 'status', 'is_primary'])->map(fn (object $office): array => (array) $office)->all(),
            'canManage' => $this->authorization->can($organization, $request->user(), 'office.settings.manage'),
            'types' => self::TYPES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'trade_name' => 'required|string|max:160',
            'legal_name' => 'nullable|string|max:190',
            'tax_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            'organization_type' => ['nullable', Rule::in(self::TYPES)],
            'founded_year' => 'nullable|integer|min:1800|max:'.now()->year,
            'website' => 'nullable|url:http,https|max:190',
            'description' => 'nullable|string|max:500',
            'country' => 'required|string|max:60',
            'department' => 'nullable|string|max:80',
            'municipality' => 'nullable|string|max:80',
            'fiscal_address' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'general_email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'billing_email' => 'nullable|email|max:190',
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', Rule::in(['GTQ', 'USD'])],
            'date_format' => ['required', Rule::in(['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'])],
            'number_format' => ['required', Rule::in(['es-GT', 'en-US'])],
            'logo_in_reports' => 'required|boolean',
            'letterhead_in_communications' => 'required|boolean',
        ]);

        DB::transaction(function () use ($organization, $data, $request): void {
            $this->profile($organization);
            DB::table('firm_profiles')->where('organization_id', $organization->id)->update([...$data, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table('organizations')->where('id', $organization->id)->update([
                'name' => $data['trade_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'tax_identifier' => $data['tax_id'] ?? null,
                'phone' => $data['phone'] ?? null,
                'timezone' => $data['timezone'],
                'currency_code' => $data['currency'],
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $request->user(), 'firm.profile_updated', 'organizations', $organization->id, ['trade_name' => $data['trade_name']]);
        });

        return back()->with('workspace_notice', 'Perfil de la firma guardado correctamente.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg|max:1024']);
        $profile = $this->profile($organization);
        $path = $request->file('logo')->store('firm-logos/'.$organization->id);

        if ($profile->logo_path) {
            Storage::delete($profile->logo_path);
        }

        DB::table('firm_profiles')->where('organization_id', $organization->id)->update(['logo_path' => $path, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
        $this->accounting->audit($organization, $request->user(), 'firm.logo_updated', 'organizations', $organization->id);

        return back()->with('workspace_notice', 'Logo actualizado correctamente.');
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $profile = $this->profile($organization);

        if ($profile->logo_path) {
            Storage::delete($profile->logo_path);
            DB::table('firm_profiles')->where('organization_id', $organization->id)->update(['logo_path' => null, 'updated_at' => now()]);
            $this->accounting->audit($organization, $request->user(), 'firm.logo_removed', 'organizations', $organization->id);
        }

        return back()->with('workspace_notice', 'Logo eliminado correctamente.');
    }

    public function logo(Request $request): StreamedResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->canView($organization, $request), 403);
        $path = $this->profile($organization)->logo_path;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path);
    }

    private function canView(Organization $organization, Request $request): bool
    {
        return $this->authorization->can($organization, $request->user(), 'office.executive.view')
            || $this->authorization->can($organization, $request->user(), 'office.dashboard.view')
            || $this->authorization->can($organization, $request->user(), 'office.settings.manage');
    }

    private function manage(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.settings.manage'), 403, 'Solo el Administrador de la firma edita el perfil.');

        return $organization;
    }

    /** The profile row, created from the organization's own data the first time it is needed. */
    private function profile(Organization $organization): stdClass
    {
        $existing = DB::table('firm_profiles')->where('organization_id', $organization->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        DB::table('firm_profiles')->insert([
            'organization_id' => $organization->id,
            'trade_name' => $organization->name,
            'legal_name' => DB::table('organizations')->where('id', $organization->id)->value('legal_name'),
            'tax_id' => DB::table('organizations')->where('id', $organization->id)->value('tax_identifier'),
            'phone' => DB::table('organizations')->where('id', $organization->id)->value('phone'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('firm_profiles')->where('organization_id', $organization->id)->first();
    }
}
