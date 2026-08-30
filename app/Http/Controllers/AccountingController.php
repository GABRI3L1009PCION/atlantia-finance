<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class AccountingController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting) {}

    /** GET accounting: company directory, scoped by office and assignment. No writes. */
    public function index(Request $request): Response
    {
        $org = $this->context->organization($request->user());
        $search = mb_substr((string) $request->query('search', ''), 0, 100);
        $companies = $this->accounting->companies($org, $request->user());
        if ($search !== '') {
            $companies->where(fn (Builder $q) => $q->where('companies.name', 'like', '%'.$search.'%')->orWhere('companies.tax_id', 'like', '%'.$search.'%'));
        }

        return Inertia::render('accounting/index', [
            'organizationName' => $org->name,
            'canManage' => $org->pivot->role === 'owner',
            'companies' => $companies->orderBy('companies.name')->paginate(12)->withQueryString(),
            'offices' => $org->pivot->role === 'owner' ? $org->offices()->where('status', 'active')->get(['id', 'name']) : [],
            'search' => $search,
            'notice' => session('accounting_notice'),
        ]);
    }

    /** POST accounting/companies: create company under an owned office; enforce plan quota. */
    public function storeCompany(Request $request): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'], 'email' => 'nullable|email|max:255', 'office_id' => 'required|integer'], $this->messages());
        $uuid = (string) Str::uuid();
        DB::transaction(function () use ($request, $org, $data, $uuid): void {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->firstOrFail();
            $office = $org->offices()->where('status', 'active')->whereKey((int) $data['office_id'])->firstOrFail();
            $all = DB::table('companies')->whereIn('office_id', DB::table('offices')->where('organization_id', $org->id)->select('id'));
            if ((clone $all)->count() >= $this->context->limit($org, 'active_cases')) {
                throw ValidationException::withMessages(['tax_id' => 'Alcanzaste el límite de expedientes del plan.']);
            }
            $taxId = strtoupper($data['tax_id']);
            if ((clone $all)->where('tax_id', $taxId)->exists()) {
                throw ValidationException::withMessages(['tax_id' => 'Esta empresa ya está registrada en tu organización.']);
            }
            $id = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => $data['name'], 'tax_id' => $taxId, 'email' => $data['email'] ?? null, 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->installDefaultChart($id);
            $this->accounting->audit($org, $request->user(), 'company.created', 'companies', $id);
        });

        return redirect()->route('accounting.show', $uuid)->with('accounting_notice', 'Empresa creada con un catálogo contable inicial. Revisa las cuentas y abre el primer período.');
    }

    /** GET company workspace: accounts, periods, journal and posted ledger, never other tenants. */
    public function show(Request $request, string $company): Response
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company);
        $owner = $org->pivot->role === 'owner';
        $periods = DB::table('accounting_periods')->where('company_id', $record->id)->orderByDesc('starts_on')->get();
        $periodId = $request->integer('period');
        $accountId = $request->integer('account');
        $accounts = DB::table('ledger_accounts')->where('company_id', $record->id)->orderBy('code')->get();
        abort_if($periodId && ! $periods->contains('id', $periodId), 404);
        abort_if($accountId && ! $accounts->contains('id', $accountId), 404);
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $record->id)->when($periodId, fn (Builder $q) => $q->where('p.id', $periodId));
        $journal = (clone $entries)->join('users as creator', 'creator.id', '=', 'e.created_by')->leftJoin('users as approver', 'approver.id', '=', 'e.posted_by')
            ->select('e.*', 'p.name as period_name', 'creator.name as author', 'approver.name as approver')
            ->selectSub(DB::table('journal_entries as r')->select('r.public_id')->whereColumn('r.reversal_of', 'e.id')->limit(1), 'reversed_by')
            ->orderByDesc('e.entry_date')->orderByDesc('e.id')->paginate(15, ['*'], 'journal_page')->withQueryString();
        $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->whereIn('l.journal_entry_id', $journal->getCollection()->pluck('id'))->where('a.company_id', $record->id)
            ->select('l.*', 'a.code', 'a.name')->orderBy('l.position')->get()->groupBy('journal_entry_id');
        $documents = DB::table('journal_entry_documents')->whereIn('journal_entry_id', $journal->getCollection()->pluck('id'))
            ->select('public_id', 'journal_entry_id', 'original_name', 'mime_type', 'size_bytes', 'created_at')->orderBy('created_at')->get()->groupBy('journal_entry_id');
        $journal->getCollection()->transform(function ($entry) use ($documents, $lines) {
            $entry->lines = $lines->get($entry->id, collect());
            $entry->documents = $documents->get($entry->id, collect());

            return $entry;
        });
        $posted = (clone $entries)->where('e.status', 'posted')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('a.company_id', $record->id);
        $balances = (clone $posted)->select('a.id', 'a.code', 'a.name', 'a.type')->selectRaw('SUM(l.debit_cents) as debit_cents, SUM(l.credit_cents) as credit_cents')->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get();
        $ledger = $accountId ? (clone $posted)->where('a.id', $accountId)->select('e.entry_date', 'e.number', 'e.description', 'p.name as period_name', 'l.debit_cents', 'l.credit_cents')->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.position')->paginate(25, ['*'], 'ledger_page')->withQueryString() : null;
        $members = $owner ? $org->users()->wherePivot('status', 'active')->wherePivot('role', '!=', 'owner')
            ->whereHas('offices', fn ($q) => $q->where('offices.id', $record->office_id)->where('office_user.status', 'active'))->get(['users.id', 'users.name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'permission' => DB::table('company_user')->where('company_id', $record->id)->where('user_id', $user->id)->value('permission') ?? 'none']) : [];

        return Inertia::render('accounting/company', [
            'accountHistory' => $owner ? DB::table('audit_logs as log')->leftJoin('users as actor', 'actor.id', '=', 'log.user_id')
                ->where('log.organization_id', $org->id)->where('log.auditable_type', 'ledger_accounts')->where('log.action', 'account.updated')
                ->whereIn('log.auditable_id', $accounts->pluck('id'))->orderByDesc('log.id')
                ->select('log.id', 'log.occurred_at', 'log.old_values', 'log.new_values', 'actor.name as actor')->limit(30)->get()
                ->map(fn ($log) => ['id' => $log->id, 'date' => $log->occurred_at, 'actor' => $log->actor, 'before' => json_decode($log->old_values, true), 'after' => json_decode($log->new_values, true)]) : [],
            'company' => $record, 'canManage' => $owner,
            'canWrite' => $owner || DB::table('company_user')->where('company_id', $record->id)->where('user_id', $request->user()->id)->where('permission', 'editor')->exists(),
            'accounts' => $accounts, 'periods' => $periods, 'journal' => $journal, 'balances' => $balances, 'ledger' => $ledger,
            'members' => $members, 'filters' => ['period' => $periodId, 'account' => $accountId], 'notice' => session('accounting_notice'),
        ]);
    }

    /** POST company assignments: owner grants viewer/editor or revokes access for an office member. */
    public function assign(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate(['user_id' => 'required|integer', 'permission' => ['required', Rule::in(['none', 'viewer', 'editor'])]], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            $member = $org->users()->wherePivot('status', 'active')->wherePivot('role', '!=', 'owner')->whereKey((int) $data['user_id'])->firstOrFail();
            abort_unless($member->offices()->where('offices.id', $record->office_id)->wherePivot('status', 'active')->exists(), 422);
            if ($data['permission'] === 'none') {
                DB::table('company_user')->where('company_id', $record->id)->where('user_id', $member->id)->delete();
            } else {
                DB::table('company_user')->updateOrInsert(['company_id' => $record->id, 'user_id' => $member->id], ['permission' => $data['permission']]);
            }
            $this->accounting->audit($org, $request->user(), 'company.assignment.'.$data['permission'], 'companies', $record->id, ['user_id' => $member->id]);
        });

        return back()->with('accounting_notice', 'Permisos actualizados.');
    }

    /** POST accounts: owner defines a posting account in this company. */
    public function account(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate(['code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/'], 'name' => 'required|string|max:150', 'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])]], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('ledger_accounts')->where('company_id', $record->id)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Ese código ya existe en esta empresa.']);
            }
            $id = DB::table('ledger_accounts')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'account.created', 'ledger_accounts', $id);
        });

        return back()->with('accounting_notice', 'Cuenta contable creada.');
    }

    /** POST account template: add missing starter accounts without altering existing codes. */
    public function accountTemplate(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $created = DB::transaction(function () use ($request, $org, $record): int {
            $this->accounting->lock($record->id);
            $created = $this->accounting->installDefaultChart($record->id);
            $this->accounting->audit($org, $request->user(), 'account.template.installed', 'companies', $record->id, ['created' => $created]);

            return $created;
        });

        return back()->with('accounting_notice', $created ? "Plantilla instalada: {$created} cuentas agregadas." : 'El catálogo ya contiene toda la plantilla inicial.');
    }

    /**
     * PATCH company/accounts/{account}: owner corrects code, name and type in ledger_accounts.
     * Keeps journal_lines and entries unchanged; audit_logs stores before/after and reason.
     * Closed posted periods freeze account metadata; company lock serializes with posting/closure.
     */
    public function updateAccount(Request $request, string $company, int $account): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/'],
            'name' => 'required|string|max:150',
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'reason' => 'required|string|max:350',
            'acknowledged' => 'accepted',
        ], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $account, $data): void {
            $this->accounting->lock($record->id);
            $row = DB::table('ledger_accounts')->where('company_id', $record->id)->where('id', $account)->firstOrFail();
            $before = ['code' => $row->code, 'name' => $row->name, 'type' => $row->type];
            $after = array_intersect_key($data, $before);
            if ($before === $after) {
                return;
            }
            $closed = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
                ->where('l.ledger_account_id', $account)->where('e.status', 'posted')->whereNotNull('p.closed_at')->exists();
            if ($closed) {
                throw ValidationException::withMessages(['accounting' => 'Esta cuenta tiene movimientos en períodos cerrados. No se permite modificar sus datos.']);
            }
            if (DB::table('ledger_accounts')->where('company_id', $record->id)->where('code', $data['code'])->where('id', '!=', $account)->exists()) {
                throw ValidationException::withMessages(['code' => 'Ese código ya existe en esta empresa.']);
            }
            DB::table('ledger_accounts')->where('id', $account)->update([...$after, 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'organization_id' => $org->id, 'user_id' => $request->user()->id,
                'action' => 'account.updated', 'auditable_type' => 'ledger_accounts', 'auditable_id' => $account,
                'old_values' => json_encode($before, JSON_THROW_ON_ERROR),
                'new_values' => json_encode([...$after, 'reason' => $data['reason']], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(),
            ]);
        });

        return back()->with('accounting_notice', 'Cuenta revisada. Los cambios se conservan en el historial; los importes no se modificaron.');
    }

    /** POST periods: reject overlapping date intervals, including already closed periods. */
    public function period(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate(['name' => 'required|string|max:80', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on'], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('accounting_periods')->where('company_id', $record->id)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Las fechas se superponen con otro período.']);
            }
            $id = DB::table('accounting_periods')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'period.created', 'accounting_periods', $id);
        });

        return back()->with('accounting_notice', 'Período abierto.');
    }

    /** POST close: blocks subsequent posting; no financial closing entries are generated. */
    public function close(Request $request, string $company, int $period): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        DB::transaction(function () use ($request, $org, $record, $period): void {
            $this->accounting->lock($record->id);
            DB::table('accounting_periods')->where('company_id', $record->id)->where('id', $period)->firstOrFail();
            if (DB::table('journal_entries')->where('accounting_period_id', $period)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['accounting' => 'Aprueba o descarta los borradores antes de cerrar el período.']);
            }
            $updated = DB::table('accounting_periods')->where('id', $period)->whereNull('closed_at')->update(['closed_at' => now(), 'closed_by' => $request->user()->id, 'updated_at' => now()]);
            if ($updated) {
                $this->accounting->audit($org, $request->user(), 'period.closed', 'accounting_periods', $period);
            }
        });

        return back()->with('accounting_notice', 'Período cerrado para nuevas contabilizaciones.');
    }

    /** POST entries: creates balanced draft + lines atomically; no client-supplied status accepted. */
    public function entry(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $data = $request->validate(['request_id' => 'required|uuid', 'period_id' => 'required|integer', 'entry_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|max:500', 'reference' => 'nullable|string|max:80', 'kind' => ['required', Rule::in(['standard', 'opening', 'adjustment', 'closing'])], 'lines' => 'required|array|min:2|max:100', 'lines.*.account_id' => 'required|integer', 'lines.*.debit' => 'required', 'lines.*.credit' => 'required'], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('journal_entries')->where('public_id', $data['request_id'])->exists()) {
                throw ValidationException::withMessages(['accounting' => 'Esta solicitud ya fue registrada. Consulta el diario antes de volver a enviarla.']);
            }
            $this->accounting->company($org, $request->user(), $record->public_id, true);
            $period = $this->accounting->openPeriod($record->id, $data['period_id'], $data['entry_date']);
            if ($data['kind'] === 'opening' && $data['entry_date'] !== $period->starts_on) {
                throw ValidationException::withMessages(['accounting' => 'Los saldos iniciales deben registrarse al inicio del período.']);
            }
            $lines = $this->accounting->lines($record->id, $data['lines']);
            $id = DB::table('journal_entries')->insertGetId(['public_id' => $data['request_id'], 'accounting_period_id' => $period->id, 'entry_date' => $data['entry_date'], 'description' => $data['description'], 'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null, 'kind' => $data['kind'], 'status' => 'draft', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('journal_lines')->insert(array_map(fn ($line) => [...$line, 'journal_entry_id' => $id], $lines));
            $this->accounting->audit($org, $request->user(), 'entry.drafted', 'journal_entries', $id);
        });

        return back()->with('accounting_notice', 'Borrador guardado. El propietario debe aprobarlo para que afecte los libros.');
    }

    /** POST entry action: owner approves, voids a draft, or posts an exact linked reversal. */
    public function process(Request $request, string $company, string $entry): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate(['action' => ['required', Rule::in(['post', 'void', 'reverse'])], 'period_id' => 'required_if:action,reverse|nullable|integer', 'entry_date' => 'required_if:action,reverse|nullable|date_format:Y-m-d', 'reason' => 'required_if:action,reverse|required_if:action,void|nullable|string|max:350'], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $entry, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->row(DB::table('journal_entries')->whereIn('accounting_period_id', DB::table('accounting_periods')->where('company_id', $record->id)->select('id'))->where('public_id', $entry));
            if ($data['action'] === 'post') {
                $this->accounting->post($record->id, $row, $request->user());
            } elseif ($data['action'] === 'void') {
                if ($row->status !== 'draft') {
                    throw ValidationException::withMessages(['accounting' => 'Solo puedes descartar borradores. Usa un reverso para los asientos contabilizados.']);
                }
                DB::table('journal_entries')->where('id', $row->id)->update(['status' => 'void', 'updated_at' => now()]);
            } else {
                if ($row->status !== 'posted' || $row->reversal_of || DB::table('journal_entries')->where('reversal_of', $row->id)->exists()) {
                    throw ValidationException::withMessages(['accounting' => 'Este asiento no admite otro reverso.']);
                }
                $period = $this->accounting->openPeriod($record->id, $data['period_id'], $data['entry_date']);
                if ($data['entry_date'] < $row->entry_date) {
                    throw ValidationException::withMessages(['accounting' => 'El reverso no puede ser anterior al asiento original.']);
                }
                $newId = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'accounting_period_id' => $period->id, 'entry_date' => $data['entry_date'], 'description' => 'Reverso: '.$data['reason'], 'kind' => 'reversal', 'status' => 'draft', 'created_by' => $request->user()->id, 'reversal_of' => $row->id, 'created_at' => now(), 'updated_at' => now()]);
                $lines = DB::table('journal_lines')->where('journal_entry_id', $row->id)->get();
                DB::table('journal_lines')->insert($lines->map(fn ($l) => ['journal_entry_id' => $newId, 'ledger_account_id' => $l->ledger_account_id, 'position' => $l->position, 'debit_cents' => $l->credit_cents, 'credit_cents' => $l->debit_cents])->all());
                $this->accounting->post($record->id, $this->row(DB::table('journal_entries')->where('id', $newId)), $request->user());
                $this->accounting->audit($org, $request->user(), 'entry.reversal.created', 'journal_entries', $newId);
            }
            $this->accounting->audit($org, $request->user(), 'entry.'.$data['action'], 'journal_entries', $row->id, ['reason' => $data['reason'] ?? null]);
        });

        return back()->with('accounting_notice', 'Operación registrada en la bitácora contable.');
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['required' => 'Este campo es obligatorio.', 'required_if' => 'Este campo es obligatorio para esta operación.', 'integer' => 'Selecciona una opción válida.', 'in' => 'Selecciona una opción válida.', 'date_format' => 'Usa una fecha válida.', 'after_or_equal' => 'La fecha final no puede ser anterior a la inicial.', 'max' => 'El valor supera el tamaño permitido.', 'min' => 'Agrega al menos dos líneas.', 'regex' => 'El formato no es válido.', 'email' => 'Escribe un correo válido.', 'uuid' => 'Recarga la página e inténtalo nuevamente.'];
    }

    private function row(Builder $query): stdClass
    {
        return $query->firstOrFail();
    }
}
