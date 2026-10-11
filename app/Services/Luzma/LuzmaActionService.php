<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Payables\PayableService;
use App\Services\Payables\PaymentProposalService;
use App\Services\WorkManagement\WorkTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Actions Luzma can prepare. Luzma only prepares: the user sees a preview and confirms, then Laravel re-checks the
 * permission, the tenant and the company and runs the same deterministic service the module uses. Critical
 * operations (closing, approving, paying, voiding) are not in this list and can never be prepared.
 */
class LuzmaActionService
{
    /** type => [side effect level, permissions required besides luzma.actions.execute, label] */
    public const TYPES = [
        'create_reminder' => [LuzmaTool::LOW_RISK_WRITE, ['luzma.reminders.create'], 'Crear recordatorio'],
        'create_task' => [LuzmaTool::LOW_RISK_WRITE, ['tasks.create'], 'Crear tarea'],
        'create_payment_proposal' => [LuzmaTool::PREPARE, ['payables.payment_proposals.create'], 'Crear propuesta de pago en borrador'],
    ];

    public function __construct(
        private LuzmaContextService $contexts,
        private LuzmaDateParser $dates,
        private LuzmaReminderService $reminders,
        private LuzmaAuditService $audit,
    ) {}

    /**
     * Builds the preview of an action from the user's words. Returns a message instead when data is missing.
     *
     * @return array{action: array<string, mixed>|null, message: string}
     */
    public function prepare(LuzmaContext $context, string $tool, string $message, ?int $conversationId): array
    {
        return match ($tool) {
            'prepare_reminder' => $this->prepareReminder($context, $message, $conversationId),
            'prepare_task' => $this->prepareTask($context, $message, $conversationId),
            'prepare_payment_proposal' => $this->prepareProposal($context, $conversationId),
            default => ['action' => null, 'message' => 'No puedo preparar esa acción.'],
        };
    }

    /**
     * @return array{message: string, href: string|null}
     */
    public function confirm(LuzmaContext $context, string $publicId): array
    {
        $row = $this->pending($context, $publicId);
        [, $permissions] = self::TYPES[$row->type];
        abort_unless($context->can('luzma.actions.execute') && $context->canAny($permissions), 403, 'Tu rol no puede ejecutar esta acción.');
        if ($row->company_id !== null && ! in_array((int) $row->company_id, $context->companyIds, true)) {
            abort(403, 'Ya no tienes acceso a esa empresa.');
        }
        $payload = (array) json_decode((string) $row->payload, true);
        $this->audit->record($context->organization, $context->user, 'luzma.action.confirmed', 'luzma_actions', (int) $row->id, ['type' => $row->type]);
        try {
            $result = DB::transaction(fn (): array => $this->execute($context, $row, $payload));
        } catch (Throwable $exception) {
            $error = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : 'No se pudo completar la acción.';
            DB::table('luzma_actions')->where('id', $row->id)->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'confirmed_at' => now(), 'updated_at' => now()]);
            $this->audit->record($context->organization, $context->user, 'luzma.action.failed', 'luzma_actions', (int) $row->id, ['type' => $row->type]);

            throw ValidationException::withMessages(['action' => $error]);
        }
        DB::table('luzma_actions')->where('id', $row->id)->update(['status' => 'executed', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'confirmed_at' => now(), 'executed_at' => now(), 'updated_at' => now()]);
        $this->audit->record($context->organization, $context->user, 'luzma.action.executed', 'luzma_actions', (int) $row->id, ['type' => $row->type]);

        return $result;
    }

    public function cancel(LuzmaContext $context, string $publicId): void
    {
        $row = $this->pending($context, $publicId);
        DB::table('luzma_actions')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    private function execute(LuzmaContext $context, stdClass $row, array $payload): array
    {
        switch ($row->type) {
            case 'create_reminder':
                /** @var array{kind?: string, company_id?: int|null, title: string, message?: string|null, remind_at: string, condition_type?: string|null, condition_payload?: array<string, mixed>|null, source_type?: string|null, source_id?: int|null, destination?: string|null, priority?: string, rule_key?: string|null, initiated_via?: string} $reminder */
                $reminder = [...$payload, 'initiated_via' => 'luzma'];
                $this->reminders->create($context->organization, $context->user, $reminder);

                return ['message' => 'Listo, te lo recordaré.', 'href' => '/luzma?tab=reminders'];
            case 'create_task':
                $id = app(WorkTaskService::class)->create($context->organization, $context->user, $payload);
                DB::table('work_tasks')->where('id', $id)->update(['initiated_via' => 'luzma']);

                return ['message' => 'Tarea creada y asignada.', 'href' => '/work/tasks/'.$id];
            case 'create_payment_proposal':
                $id = app(PaymentProposalService::class)->create($context->organization, $context->user, (int) $row->company_id, [
                    'proposed_date' => (string) $payload['proposed_date'], 'priority' => (string) $payload['priority'], 'notes' => 'Preparada con Luzma.',
                    'items' => array_values(array_map(fn (array $item): array => ['kind' => 'invoice', 'purchase_document_id' => (int) $item['purchase_document_id'], 'amount_cents' => (int) $item['amount_cents']], (array) $payload['items'])),
                ]);
                DB::table('payment_proposals')->where('id', $id)->update(['initiated_via' => 'luzma']);

                return ['message' => 'Propuesta creada en borrador. Revísala y envíala a aprobación; no reduce saldos ni paga nada.', 'href' => '/payables/proposals/'.$id];
        }

        abort(404);
    }

    /**
     * @return array{action: array<string, mixed>|null, message: string}
     */
    private function prepareReminder(LuzmaContext $context, string $message, ?int $conversationId): array
    {
        $text = $this->contexts->normalize($message);
        $date = $this->dates->parse($text, $context->timezone);
        $company = $this->contexts->companyFromText($context, $message) ?? ($context->companyId === null ? null : ['id' => $context->companyId, 'name' => (string) $context->companyName]);
        $condition = null;
        if (preg_match('/\b(avisame|avisarme|notificame) si\b/u', $text) === 1) {
            $condition = match (true) {
                preg_match('/(cxp|cuentas por pagar|proveedor)/u', $text) === 1 => 'payables_ledger_difference',
                preg_match('/(cxc|cuentas por cobrar|cartera)/u', $text) === 1 => 'receivables_ledger_difference',
                preg_match('/(banco|bancari|concilia)/u', $text) === 1 => 'bank_unreconciled',
                preg_match('/(fiscal|impuesto|iva|declaraci|obligaci)/u', $text) === 1 => 'fiscal_pending',
                default => null,
            };
            if ($condition === null) {
                return ['action' => null, 'message' => 'Puedo avisarte si CxP o CxC siguen sin cuadrar con el Mayor, si quedan movimientos bancarios sin conciliar o si hay obligaciones fiscales pendientes. ¿Cuál de esas condiciones quieres vigilar?'];
            }
            if ($company === null) {
                return ['action' => null, 'message' => 'Dime de qué empresa, o abre la empresa y pídemelo desde ahí; no mezclo empresas en una condición.'];
            }
        }
        if ($date === null) {
            return ['action' => null, 'message' => '¿Cuándo quieres que te lo recuerde? Por ejemplo: "mañana a las 9", "el viernes" o "en 2 horas".'];
        }
        $subject = trim((string) preg_replace('/\s+/', ' ', str_replace($date['phrase'], '', (string) preg_replace('/^(por favor\s+)?(recuerdame|recordarme|avisame|avisarme|notificame|crea un recordatorio para|un recordatorio para)\s*(que\s+|de\s+|para\s+)?/u', '', $text))));
        $subject = $subject === '' ? 'Revisar pendiente' : mb_strtoupper(mb_substr($subject, 0, 1)).mb_substr($subject, 1);
        $payload = ['kind' => $condition === null ? 'manual' : 'conditional', 'company_id' => $company['id'] ?? null, 'title' => mb_substr($subject, 0, 180), 'remind_at' => $date['at']->toIso8601String(),
            'condition_type' => $condition, 'priority' => 'normal', 'destination' => $condition === null ? null : match ($condition) {
                'payables_ledger_difference' => '/payables/reconciliation?company_id='.$company['id'],
                'receivables_ledger_difference' => '/receivables/reconciliation?company_id='.$company['id'],
                'bank_unreconciled' => '/treasury/movements', default => '/tax/obligations',
            }];
        $when = $this->human($date['at'], true);
        $preview = ['title' => $payload['title'], 'when' => $when, 'company' => $company['name'] ?? null,
            'summary' => $condition === null ? 'Te recordaré "'.$payload['title'].'" el '.$when.'.'
                : 'El '.$when.' revisaré si '.mb_strtolower(LuzmaReminderConditionService::CONDITIONS[$condition]).' en '.$company['name'].'. Solo te aviso si sigue pendiente; si ya se resolvió, no te molesto.'];

        return ['action' => $this->store($context, 'create_reminder', $payload, $preview, $company['id'] ?? null, $conversationId), 'message' => $preview['summary']];
    }

    /**
     * @return array{action: array<string, mixed>|null, message: string}
     */
    private function prepareTask(LuzmaContext $context, string $message, ?int $conversationId): array
    {
        $company = $this->contexts->companyFromText($context, $message) ?? ($context->companyId === null ? null : ['id' => $context->companyId, 'name' => (string) $context->companyName]);
        if ($company === null) {
            return ['action' => null, 'message' => '¿Para qué empresa es la tarea? Ábrela o menciónala en tu mensaje.'];
        }
        $text = $this->contexts->normalize($message);
        $date = $this->dates->parse($text, $context->timezone);
        $title = trim((string) preg_replace('/^.*?\btarea\s*(para\s+|de\s+|:\s*)?/u', '', $message));
        if ($date !== null) {
            $title = trim(str_ireplace($date['phrase'], '', $this->contexts->normalize($title)));
        }
        if (mb_strlen($title) < 4) {
            return ['action' => null, 'message' => '¿Qué debe hacerse en la tarea? Descríbela en una frase.'];
        }
        $office = (int) DB::table('companies')->where('id', $company['id'])->value('office_id');
        $payload = ['office_id' => $office, 'company_id' => $company['id'], 'accounting_period_id' => $context->companyId === $company['id'] ? ($context->period['id'] ?? null) : null, 'module' => 'general', 'task_type' => 'luzma',
            'title' => mb_substr(mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1), 0, 180), 'assigned_to' => $context->user->id, 'priority' => 'normal', 'due_at' => $date === null ? null : $date['at']->toIso8601String()];
        $preview = ['title' => $payload['title'], 'company' => $company['name'], 'assignee' => $context->user->name, 'due' => $date === null ? 'Sin fecha' : $this->human($date['at'], true),
            'summary' => 'Crearé la tarea "'.$payload['title'].'" en '.$company['name'].', asignada a ti'.($date === null ? '' : ', con vencimiento '.$this->human($date['at'], false)).'.'];

        return ['action' => $this->store($context, 'create_task', $payload, $preview, $company['id'], $conversationId), 'message' => $preview['summary']];
    }

    /**
     * @return array{action: array<string, mixed>|null, message: string}
     */
    private function prepareProposal(LuzmaContext $context, ?int $conversationId): array
    {
        if ($context->companyId === null) {
            return ['action' => null, 'message' => 'Selecciona la empresa: cada propuesta de pago es de una sola empresa.'];
        }
        $documents = array_values(array_filter(app(PayableService::class)->documents([$context->companyId]), fn (array $row): bool => ($row['overdue'] || $row['days_to_due'] <= 7)
            && ! $row['in_proposal'] && $row['committed_cents'] === 0 && ! $row['disputed'] && $row['outstanding_cents'] > 0));
        if ($documents === []) {
            return ['action' => null, 'message' => 'No hay obligaciones vencidas o por vencer en 7 días sin propuesta ni pago en proceso en '.$context->companyName.'.'];
        }
        usort($documents, fn (array $a, array $b): int => [$b['overdue_days'], $a['due_date']] <=> [$a['overdue_days'], $b['due_date']]);
        $documents = array_slice($documents, 0, 25);
        $total = array_sum(array_column($documents, 'outstanding_cents'));
        $payload = ['proposed_date' => Carbon::parse($context->today())->addDay()->toDateString(), 'priority' => collect($documents)->contains('overdue', true) ? 'high' : 'normal',
            'items' => array_map(fn (array $row): array => ['purchase_document_id' => $row['id'], 'amount_cents' => $row['outstanding_cents']], $documents)];
        $preview = ['company' => $context->companyName, 'total' => LuzmaToolRegistry::money($total), 'proposed_date' => $payload['proposed_date'],
            'lines' => array_map(fn (array $row): string => $row['document'].' · '.$row['supplier'].' · '.LuzmaToolRegistry::money($row['outstanding_cents']).($row['overdue'] ? ' · '.$row['overdue_days'].' días vencido' : ' · vence '.$row['due_date']), $documents),
            'summary' => 'Puedo preparar una propuesta de pago en borrador por '.LuzmaToolRegistry::money($total).' con '.count($documents).' obligación(es) de '.$context->companyName.'. No paga nada: después debe aprobarla otra persona y Tesorería la ejecuta.'];

        return ['action' => $this->store($context, 'create_payment_proposal', $payload, $preview, $context->companyId, $conversationId), 'message' => $preview['summary']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function store(LuzmaContext $context, string $type, array $payload, array $preview, ?int $companyId, ?int $conversationId): array
    {
        [$level, , $label] = self::TYPES[$type];
        $publicId = (string) Str::uuid();
        $id = DB::table('luzma_actions')->insertGetId([
            'public_id' => $publicId, 'organization_id' => $context->organization->id, 'company_id' => $companyId, 'user_id' => $context->user->id, 'luzma_conversation_id' => $conversationId,
            'type' => $type, 'side_effect_level' => $level, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'preview' => json_encode($preview, JSON_THROW_ON_ERROR),
            'status' => 'prepared', 'expires_at' => now()->addMinutes((int) config('luzma.action_ttl_minutes')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record($context->organization, $context->user, 'luzma.action.prepared', 'luzma_actions', $id, ['type' => $type]);

        return ['id' => $publicId, 'type' => $type, 'label' => $label, 'level' => $level, 'preview' => $preview, 'status' => 'prepared', 'confirm_label' => $level === LuzmaTool::PREPARE ? 'Preparar borrador' : 'Confirmar'];
    }

    private function human(CarbonImmutable $date, bool $withTime): string
    {
        $days = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $days[$date->dayOfWeekIso - 1].' '.$date->day.' de '.$months[$date->month - 1].($withTime ? ' a las '.$date->format('H:i') : '');
    }

    private function pending(LuzmaContext $context, string $publicId): stdClass
    {
        $row = DB::table('luzma_actions')->where('luzma_actions.organization_id', $context->organization->id)->where('user_id', $context->user->id)->where('public_id', $publicId)->first();
        abort_if($row === null || ! isset(self::TYPES[$row->type]), 404);
        if ($row->status !== 'prepared') {
            throw ValidationException::withMessages(['action' => 'Esta acción ya fue '.['executed' => 'ejecutada', 'failed' => 'intentada sin éxito', 'cancelled' => 'cancelada', 'expired' => 'invalidada'][$row->status].'.']);
        }
        if (Carbon::parse($row->expires_at)->isPast()) {
            DB::table('luzma_actions')->where('id', $row->id)->update(['status' => 'expired', 'updated_at' => now()]);

            throw ValidationException::withMessages(['action' => 'La vista previa expiró porque los datos pudieron cambiar. Pídeme prepararla de nuevo.']);
        }

        return $row;
    }
}
