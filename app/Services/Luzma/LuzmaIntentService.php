<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Luzma\Contracts\LuzmaModelProvider;

/**
 * Decides what a question needs. Laravel rules come first (critical orders, reminders, clear matches), the local
 * model only plans ambiguous questions with structured output, and whatever it proposes is re-validated against
 * the tools this user may use. The model never chooses an action outside the registry.
 */
class LuzmaIntentService
{
    /** Orders Luzma never carries out on its own; it explains and points to the controlled process. */
    public const CRITICAL = [
        'close_period' => ['/^(por favor\s+)?(cierra|cerrar|haz el cierre|realiza el cierre)\b/u', 'Cerrar un período'],
        'reopen_period' => ['/\b(reabre|reabrir|reapertura)\b/u', 'Reabrir un período'],
        'approve' => ['/^(por favor\s+)?(aprueba|aprobar|autoriza|autorizar)\b/u', 'Aprobar'],
        'execute_payment' => ['/\b(ejecuta|ejecutar|transfiere|transferir|realiza el pago|haz el pago|envia el dinero)\b/u', 'Ejecutar un pago'],
        'void' => ['/^(por favor\s+)?(anula|anular|cancela la factura|cancelar la factura)\b/u', 'Anular un documento'],
        'delete' => ['/^(por favor\s+)?(elimina|eliminar|borra|borrar)\b/u', 'Eliminar información contable'],
        'modify_posted' => ['/\b(modifica|cambia|corrige) (el|este) asiento\b/u', 'Modificar un asiento contabilizado'],
        'permissions' => ['/\b(cambia|asigna|quita|dame) (el |los |mis )?(rol|roles|permiso|permisos)\b/u', 'Cambiar roles o permisos'],
    ];

    /** Default read tool of each screen, used when a contextual question does not name a topic. */
    private const MODULE_DEFAULTS = [
        'payables' => 'get_payables_summary', 'purchases' => 'get_payables_summary', 'receivables' => 'get_receivables_summary', 'sales' => 'get_receivables_summary',
        'treasury' => 'get_treasury_summary', 'banks' => 'get_treasury_summary', 'reconciliation' => 'get_treasury_summary', 'tax' => 'get_fiscal_status', 'fel' => 'get_fiscal_status',
        'closing' => 'get_close_checklist', 'executive' => 'get_executive_overview', 'operations' => 'get_company_blockers', 'ledger' => 'get_account_balance', 'audit' => 'get_audit_events',
        'library' => 'search_normative', 'payroll' => 'get_payroll_summary', 'audit_desk' => 'get_audit_summary', 'client' => 'get_client_summary',
        'assistant' => 'get_my_priorities', 'accountant' => 'get_pending_reviews', 'supervisor' => 'get_pending_reviews', 'dashboard' => 'get_my_priorities',
    ];

    public function __construct(
        private LuzmaToolRegistry $tools,
        private LuzmaContextService $contexts,
        private LuzmaModelProvider $provider,
    ) {}

    /**
     * @return array{kind: string, tool: string|null, arguments: array<string, mixed>, critical: string|null, source: string}
     */
    public function route(LuzmaContext $context, string $message, ?string $previousTool = null): array
    {
        $text = $this->contexts->normalize($message);
        $available = $this->tools->available($context);
        $isQuestion = str_contains($message, '?') || preg_match('/^(que|como|por que|cual|cuando|cuanto|cuantos|donde|quien|hay|existe)\b/u', $text) === 1;

        foreach (self::CRITICAL as $type => [$pattern]) {
            if (! $isQuestion && preg_match($pattern, $text) === 1) {
                return ['kind' => 'critical', 'tool' => $type === 'close_period' && isset($available['get_close_checklist']) ? 'get_close_checklist' : null, 'arguments' => [], 'critical' => $type, 'source' => 'rules'];
            }
        }
        // Greetings and "what can you do" are presentations, not data requests: no tool is run for them.
        if ((mb_strlen($text) <= 40 && preg_match('/^(hola|buenas|buenos dias|buen dia|buenas tardes|buenas noches|hey|gracias|muchas gracias)\b/u', $text) === 1)
            || preg_match('/\bque (puedes|sabes) hacer\b|\bcomo me (puedes )?ayud|\bpara que sirves\b|\bquien eres\b|\bque eres\b/u', $text) === 1) {
            return ['kind' => 'general', 'tool' => null, 'arguments' => [], 'critical' => null, 'source' => 'rules'];
        }
        if (preg_match('/\b(recuerdame|recordarme|avisame|avisarme|recordatorio|notificame)\b/u', $text) === 1) {
            return $this->prepare('prepare_reminder', $available);
        }
        if (preg_match('/\b(crea|crear|asigna|asignar|prepara|preparar) (una |la )?tarea\b/u', $text) === 1) {
            return $this->prepare('prepare_task', $available);
        }
        if (preg_match('/\b(prepara|preparar|arma|armar|crea|crear) (una |la )?propuesta\b|\b(puedes |podrias )?pagar (estas|las|esas) (facturas|obligaciones)\b|\bpaga (estas|las|esas)\b/u', $text) === 1) {
            return $this->prepare('prepare_payment_proposal', $available);
        }

        // Memory of the conversation: a short follow-up that names no topic ("¿y los vencidos?", "dame más detalle", "¿por qué?")
        // is about the tool that answered the previous question.
        if ($previousTool !== null && isset($available[$previousTool]) && $this->isFollowUp($text) && $this->score($text, $available)[1] < 2) {
            return ['kind' => 'read', 'tool' => $previousTool, 'arguments' => [], 'critical' => null, 'source' => 'memory'];
        }

        // The neural network of the gateway reads the question and the keyword rules read it too. When the rules are sure
        // and the network disagrees, the network needs near certainty to overrule them; otherwise the rules decide.
        $planned = $this->plan($context, $message, $available);
        [$tool, $score, $tie] = $this->score($text, $available);
        $arguments = $this->arguments($tool, $text, $message);
        $rulesSure = $tool !== null && $score >= 2 && ! $tie;
        if ($planned !== null && $planned['confidence'] >= 0.8 && (! $rulesSure || $planned['route']['tool'] === $tool || $planned['confidence'] >= 0.95)) {
            return $planned['route'];
        }
        if ($rulesSure) {
            return ['kind' => 'read', 'tool' => $tool, 'arguments' => $arguments, 'critical' => null, 'source' => 'rules'];
        }

        if ($planned !== null) {
            return $planned['route'];
        }
        if ($tool !== null) {
            return ['kind' => 'read', 'tool' => $tool, 'arguments' => $arguments, 'critical' => null, 'source' => 'rules'];
        }
        $default = self::MODULE_DEFAULTS[$context->module] ?? null;
        if ($default !== null && isset($available[$default]) && $context->module !== 'global') {
            return ['kind' => 'read', 'tool' => $default, 'arguments' => $this->arguments($default, $text, $message), 'critical' => null, 'source' => 'module'];
        }

        return ['kind' => 'general', 'tool' => null, 'arguments' => [], 'critical' => null, 'source' => 'rules'];
    }

    /** Short messages that lean on the previous answer instead of naming a topic. */
    private function isFollowUp(string $text): bool
    {
        if (mb_strlen($text) > 70) {
            return false;
        }

        return preg_match('/^y (el |la |los |las |de |del |en |con |tambien |entonces )?/u', $text) === 1 && mb_strlen($text) <= 45
            || preg_match('/\b(eso|esto|esos|esas|ese|esa|mas detalle|mas informacion|dame detalle|el detalle|explicame|explicamelo|por que|cuales son|cuales|de quien|de cual|cuanto es|cuanto son|y que mas)\b/u', $text) === 1;
    }

    /**
     * @param  array<string, LuzmaTool>  $available
     * @return array{kind: string, tool: string|null, arguments: array<string, mixed>, critical: string|null, source: string}
     */
    private function prepare(string $tool, array $available): array
    {
        return isset($available[$tool])
            ? ['kind' => 'prepare', 'tool' => $tool, 'arguments' => [], 'critical' => null, 'source' => 'rules']
            : ['kind' => 'forbidden', 'tool' => $tool, 'arguments' => [], 'critical' => null, 'source' => 'rules'];
    }

    /**
     * @param  array<string, LuzmaTool>  $available
     * @return array{0: string|null, 1: int, 2: bool}
     */
    private function score(string $text, array $available): array
    {
        $scores = [];
        foreach ($available as $name => $tool) {
            if ($tool->level !== LuzmaTool::READ_ONLY) {
                continue;
            }
            $score = 0;
            foreach ($tool->keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score += str_contains($keyword, ' ') ? 3 : 1;
                }
            }
            if ($score > 0) {
                $scores[$name] = $score;
            }
        }
        // "¿Por qué CxP no coincide con Mayor?" belongs to the reconciliation, not to the general summary.
        $reconciles = preg_match('/\b(mayor|cuadra|cuadrar|coincide|concilia|diferencia)\b/u', $text) === 1;
        foreach (['get_payables_reconciliation' => '/\b(cxp|pagar|proveedor)/u', 'get_receivables_reconciliation' => '/\b(cxc|cobrar|cartera|cliente)/u', 'get_payroll_reconciliation' => '/\b(nomina|planilla|sueldos)/u'] as $name => $pattern) {
            if ($reconciles && isset($available[$name]) && preg_match($pattern, $text) === 1) {
                $scores[$name] = ($scores[$name] ?? 0) + 4;
            }
        }
        if ($scores === []) {
            return [null, 0, false];
        }
        arsort($scores);
        $top = array_key_first($scores);
        $values = array_values($scores);

        return [$top, $values[0], isset($values[1]) && $values[1] === $values[0]];
    }

    /** @return array<string, mixed> */
    private function arguments(?string $tool, string $text, string $original): array
    {
        return match ($tool) {
            'search_normative' => ['text' => $original],
            'get_account_balance' => ['account' => preg_match('/\b(\d{3,8})\b/u', $text, $code) === 1 ? $code[1]
                : (preg_match('/\bcuenta (de |del )?([a-z ]{3,40}?)(\?|$| en | por | este| esta)/u', $text, $name) === 1 ? trim($name[2]) : '')],
            'search_records' => [
                'amount' => preg_match('/q?\s?([\d.,]+)\s*(mil)?\b/u', str_replace(',', '', $text), $amount) === 1 && (float) $amount[1] > 0 ? (float) $amount[1] * (($amount[2] ?? '') === 'mil' ? 1000 : 1) : null,
                'text' => preg_match('/\b(?:de|del|a|proveedor|cliente)\s+([a-z0-9 .&]{2,40}?)(?:\s+por\b|\s+de q|\?|$)/u', $text, $who) === 1 ? trim($who[1]) : '',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, LuzmaTool>  $available
     * @return array{confidence: float, route: array{kind: string, tool: string|null, arguments: array<string, mixed>, critical: string|null, source: string}}|null
     */
    private function plan(LuzmaContext $context, string $message, array $available): ?array
    {
        $readTools = array_values(array_map(fn (LuzmaTool $tool): array => $tool->schema(), array_filter($available, fn (LuzmaTool $tool): bool => $tool->level === LuzmaTool::READ_ONLY)));
        try {
            $plan = $this->provider->plan($message, $readTools, $context->snapshot());
        } catch (LuzmaUnavailableException) {
            return null;
        }
        if ($plan === null || $plan['tool'] === null || $plan['confidence'] < 0.5) {
            return null;
        }
        $tool = $available[$plan['tool']] ?? null;
        if ($tool === null || $tool->level !== LuzmaTool::READ_ONLY) {
            return null;
        }
        $arguments = array_intersect_key($plan['arguments'], $tool->parameters);

        return ['confidence' => (float) $plan['confidence'], 'route' => ['kind' => 'read', 'tool' => $tool->name, 'arguments' => array_map(fn ($value) => is_scalar($value) ? $value : null, $arguments), 'critical' => null, 'source' => 'model']];
    }
}
