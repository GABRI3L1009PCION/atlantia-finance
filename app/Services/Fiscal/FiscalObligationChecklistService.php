<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\TaskDependencyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * The preparation checklist of a fiscal obligation and what it means for the rest of the workflow. Automatic checks
 * come from the real data (FiscalObligationReadinessService); manual steps are done by a person and stored here. An
 * obligation is only ready for review when every manual step is done and no automatic check blocks it, so starting
 * one never makes it reviewable by itself. Progress is always computed here, never typed by anybody.
 *
 * @phpstan-type CheckItem array{key: string, label: string, kind: string, status: string, detail: string|null, destination: string|null, requires_file: bool, completed_by: string|null, completed_at: string|null, note: string|null, file_name: string|null}
 */
class FiscalObligationChecklistService
{
    private const COMMON_START = [['documents_received', 'Documentación del cliente recibida y del período correcto', false]];

    private const COMMON_END = [['calculation', 'Cálculo preparado', false], ['working_paper', 'Papel de trabajo adjunto', true], ['final_validation', 'Validación final de la preparación', false]];

    private const BY_TYPE = [
        'iva' => [['sales_verified', 'Ventas verificadas contra el libro de ventas', false], ['purchases_verified', 'Compras verificadas contra el libro de compras', false],
            ['credit_notes', 'Notas de crédito y de débito consideradas', false], ['withholdings_verified', 'Retenciones de IVA verificadas', false], ['differences', 'Diferencias investigadas o justificadas', false]],
        'isr' => [['income_expenses', 'Ingresos y gastos del período verificados', false], ['tax_adjustments', 'Ajustes fiscales y pagos a cuenta revisados', false]],
        'withholding' => [['withholdings_listed', 'Retenciones del mes listadas y validadas', false], ['withheld_documents', 'Constancias de retención emitidas o recibidas', false]],
    ];

    public function __construct(
        private FiscalObligationReadinessService $readiness,
        private AccountingService $accounting,
        private TaskDependencyService $dependencies,
    ) {}

    /** @return list<array{key: string, label: string, requires_file: bool}> */
    public function definitions(string $type): array
    {
        return array_values(array_map(fn (array $item): array => ['key' => $item[0], 'label' => $item[1], 'requires_file' => $item[2]],
            [...self::COMMON_START, ...(self::BY_TYPE[$type] ?? []), ...self::COMMON_END]));
    }

    /**
     * Whole readiness picture of the obligation. When the automatic checks cannot be evaluated the result says so and is
     * never ready: an error must not look like "everything is fine".
     *
     * @return array{ready: bool, progress: int, error: string|null, checks: list<CheckItem>, completed: list<string>, pending: list<string>, blockers: list<array{label: string, detail: string|null}>, warnings: list<array{label: string, detail: string|null}>, next_action: string|null}
     */
    public function summary(stdClass $obligation): array
    {
        $manual = $this->manualItems($obligation);
        $error = null;
        $automatic = [];

        try {
            $automatic = collect($this->readiness->check($obligation)['checks'])->map(fn (array $check): array => [
                'key' => (string) $check['key'], 'label' => (string) $check['label'], 'kind' => 'automatic', 'status' => (string) $check['status'], 'detail' => $check['detail'],
                'destination' => $check['destination'] ?? null, 'requires_file' => false, 'completed_by' => null, 'completed_at' => null, 'note' => null, 'file_name' => null,
            ])->values()->all();
        } catch (Throwable) {
            $error = 'No fue posible validar la preparación.';
        }

        // Things the preparation depends on outside the checklist: what the client still owes and the tasks it requires.
        $taskId = DB::table('work_tasks')->where('work_tasks.organization_id', $obligation->organization_id)->where('automation_key', 'fiscal_obligation:'.$obligation->id)->value('id');
        if ($taskId !== null) {
            foreach (DB::table('work_task_waits')->where('work_task_id', $taskId)->where('status', 'open')->get(['kind', 'reason']) as $wait) {
                $automatic[] = ['key' => 'wait_'.$wait->kind, 'label' => ($wait->kind === 'client' ? 'Esperando al cliente' : 'Esperando a un tercero').': '.$wait->reason, 'kind' => 'wait', 'status' => 'BLOCKED', 'detail' => null, 'destination' => null,
                    'requires_file' => false, 'completed_by' => null, 'completed_at' => null, 'note' => null, 'file_name' => null];
            }
            foreach ($this->dependencies->unmet((int) $taskId) as $title) {
                $automatic[] = ['key' => 'dependency', 'label' => 'Tarea pendiente: '.$title, 'kind' => 'dependency', 'status' => 'BLOCKED', 'detail' => null, 'destination' => null, 'requires_file' => false,
                    'completed_by' => null, 'completed_at' => null, 'note' => null, 'file_name' => null];
            }
        }

        $checks = [...$manual, ...$automatic];
        $blocking = collect($automatic)->whereIn('status', ['BLOCKED', 'ERROR']);
        $pendingManual = collect($manual)->where('status', 'PENDING');
        $satisfied = collect($checks)->whereIn('status', ['PASSED', 'NOT_APPLICABLE', 'WARNING'])->count();
        $total = max(1, count($checks));
        $pending = array_values([...$pendingManual->pluck('label')->all(), ...$blocking->pluck('label')->all()]);

        return [
            'ready' => $error === null && $pending === [],
            'progress' => $error === null ? (int) floor($satisfied * 100 / $total) : 0,
            'error' => $error,
            'checks' => $checks,
            'completed' => array_values(array_map(fn (array $check): string => $check['label'], array_filter($checks, fn (array $check): bool => in_array($check['status'], ['PASSED', 'NOT_APPLICABLE'], true)))),
            'pending' => $pending,
            'blockers' => array_values($blocking->map(fn (array $check): array => ['label' => $check['label'], 'detail' => $check['detail']])->all()),
            'warnings' => array_values(collect($automatic)->where('status', 'WARNING')->map(fn (array $check): array => ['label' => $check['label'], 'detail' => $check['detail']])->all()),
            'next_action' => $pendingManual->first()['label'] ?? $blocking->first()['label'] ?? null,
        ];
    }

    /**
     * A fingerprint of everything two people could be editing at once (the obligation and its manual requirements). The screen
     * sends it back with each action; a different value means somebody changed it meanwhile.
     */
    public function version(stdClass $obligation): string
    {
        $checks = DB::table('fiscal_obligation_checks')->where('fiscal_obligation_id', $obligation->id)->selectRaw('COUNT(*) as total, MAX(updated_at) as last')->first();

        return substr(sha1($obligation->id.'|'.$obligation->status.'|'.$obligation->updated_at.'|'.($checks->total ?? 0).'|'.($checks->last ?? '')), 0, 16);
    }

    public function assertVersion(stdClass $obligation, ?string $sent): void
    {
        if ($sent !== null && $sent !== '' && ! hash_equals($this->version($obligation), $sent)) {
            throw ValidationException::withMessages(['version' => 'Otra persona modificó esta obligación mientras la editabas. Recarga la página para ver los cambios y vuelve a intentarlo.']);
        }
    }

    public function complete(Organization $organization, User $actor, stdClass $obligation, string $key, ?string $note, ?UploadedFile $file): void
    {
        $definition = collect($this->definitions((string) $obligation->obligation_type))->firstWhere('key', $key);
        if ($definition === null) {
            throw ValidationException::withMessages(['check' => 'Ese requisito no existe en esta obligación.']);
        }
        $this->assertPreparing($obligation);
        if ($definition['requires_file'] && $file === null && ! DB::table('fiscal_obligation_checks')->where('fiscal_obligation_id', $obligation->id)->where('check_key', $key)->whereNotNull('file_path')->exists()) {
            throw ValidationException::withMessages(['file' => 'Adjunta el archivo de este requisito.']);
        }

        DB::transaction(function () use ($organization, $actor, $obligation, $key, $note, $file): void {
            $path = $file === null ? null : $file->storeAs('fiscal-workpapers/'.$obligation->organization_id.'/'.$obligation->company_id, Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
            DB::table('fiscal_obligation_checks')->updateOrInsert(['fiscal_obligation_id' => $obligation->id, 'check_key' => $key], [
                'organization_id' => $organization->id, 'completed_by' => $actor->id, 'completed_at' => now(), 'note' => $note === null ? null : mb_substr($note, 0, 500),
                ...($path === null ? [] : ['file_path' => $path, 'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 250)]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'fiscal.check.completed', 'fiscal_obligations', (int) $obligation->id, ['check' => $key, 'note' => $note], (int) $obligation->office_id);
        });
    }

    public function reopen(Organization $organization, User $actor, stdClass $obligation, string $key): void
    {
        $this->assertPreparing($obligation);
        DB::transaction(function () use ($organization, $actor, $obligation, $key): void {
            DB::table('fiscal_obligation_checks')->where('fiscal_obligation_id', $obligation->id)->where('check_key', $key)->delete();
            $this->accounting->audit($organization, $actor, 'fiscal.check.reopened', 'fiscal_obligations', (int) $obligation->id, ['check' => $key], (int) $obligation->office_id);
        });
    }

    /** @return list<CheckItem> */
    private function manualItems(stdClass $obligation): array
    {
        $done = DB::table('fiscal_obligation_checks')->leftJoin('users', 'users.id', '=', 'fiscal_obligation_checks.completed_by')->where('fiscal_obligation_id', $obligation->id)
            ->get(['check_key', 'completed_at', 'note', 'file_name', 'users.name as completed_by'])->keyBy('check_key');

        return array_map(function (array $definition) use ($done): array {
            $row = $done->get($definition['key']);

            return ['key' => $definition['key'], 'label' => $definition['label'], 'kind' => 'manual', 'status' => $row === null ? 'PENDING' : 'PASSED', 'detail' => null, 'destination' => null,
                'requires_file' => $definition['requires_file'], 'completed_by' => $row === null ? null : (string) $row->completed_by, 'completed_at' => $row === null ? null : (string) $row->completed_at,
                'note' => $row?->note === null ? null : (string) $row->note, 'file_name' => $row?->file_name === null ? null : (string) $row->file_name];
        }, $this->definitions((string) $obligation->obligation_type));
    }

    private function assertPreparing(stdClass $obligation): void
    {
        if (! in_array($obligation->status, ['in_progress', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['status' => 'Inicia la obligación para trabajar sus requisitos.']);
        }
    }
}
