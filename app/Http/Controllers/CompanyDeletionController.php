<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\WorkManagement\CompanyDeletionService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The owner deletes a client company (to a 30-day trash, after a backup) or restores it. */
class CompanyDeletionController extends Controller
{
    public function __construct(private WorkspaceContext $context, private CompanyDeletionService $deletions) {}

    /** GET office/companies/{company}/deletion: what the confirmation dialog shows. */
    public function preview(Request $request, int $company): JsonResponse
    {
        return response()->json($this->deletions->preview($this->owner($request), $company));
    }

    public function destroy(Request $request, int $company): RedirectResponse
    {
        $organization = $this->owner($request);
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(CompanyDeletionService::REASONS))],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('reason') === 'other')],
            'confirm_name' => 'required|string|max:150',
            'password' => 'required|current_password:web',
        ], [
            'note.required' => 'Explica el motivo.',
            'password.current_password' => 'La contraseña no es correcta.',
        ]);
        $name = (string) DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $company)->value('name');
        if ($name === '' || mb_strtolower(trim($data['confirm_name'])) !== mb_strtolower(trim($name))) {
            throw ValidationException::withMessages(['confirm_name' => 'Escribe el nombre de la empresa exactamente como aparece para confirmar.']);
        }
        $this->deletions->delete($organization, $request->user(), $company, $data['reason'], $data['note'] ?? null, $request->ip());

        return back()->with('workspace_notice', 'La empresa '.$name.' se movió a la papelera. Puedes restaurarla durante '.CompanyDeletionService::TRASH_DAYS.' días.');
    }

    public function restore(Request $request, int $deletion): RedirectResponse
    {
        $organization = $this->owner($request);
        $this->deletions->restore($organization, $request->user(), $deletion);

        return back()->with('workspace_notice', 'Empresa restaurada con toda su información.');
    }

    private function owner(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless(CompanyDeletionService::isOwner($organization, $request->user()), 403, 'Solo el dueño de la firma puede eliminar o restaurar empresas.');

        return $organization;
    }
}
