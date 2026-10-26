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
     * @param  array<string, mixed>  $previousArguments
     * @return array{kind: string, tool: string|null, arguments: array<string, mixed>, critical: string|null, source: string, tools?: list<array{tool: string, arguments: array<string, mixed>}>}
     */
    public function route(LuzmaContext $context, string $message, ?string $previousTool = null, array $previousArguments = []): array
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
        // A collection reminder goes to the customer, not to the user: it is checked before the user's own reminders.
        if (preg_match('/\b(envia|enviale|manda|mandale|prepara|escribe|escribele|redacta)\b.{0,40}\b(recordatorio de (cobro|pago)|mensaje de cobro|cobro)\b/u', $text) === 1) {
            return $this->prepare('prepare_collection_reminder', $available);
        }
        if (preg_match('/\b(recuerdame|recordarme|avisame|avisarme|recordatorio|notificame)\b/u', $text) === 1) {
            return $this->prepare('prepare_reminder', $available);
        }
        if (preg_match('/\b(crea|crear|asigna|asignar|prepara|preparar) (una |la )?tarea\b/u', $text) === 1) {
            return $this->prepare('prepare_task', $available);
        }
        if (! $isQuestion && preg_match('/\b(registra|registrar|haz|hazme|crea|crear|prepara|preparar|carga|cargar)\s+(un |una |el |la )?(asiento|partida)\b|\breclasifica|\b(registra|genera|carga|calcula)\s+(la )?(depreciacion|amortizacion)\b/u', $text) === 1) {
            return $this->prepare('prepare_journal_entry', $available);
        }
        if (! $isQuestion && preg_match('/^(por favor\s+)?(facturale|factura a|factura para|haz(me)? una factura|crea una factura|prepara una factura|emite una factura|elabora una factura)\b/u', $text) === 1) {
            return $this->prepare('prepare_sales_invoice', $available);
        }
        if (! $isQuestion && preg_match('/^(por favor\s+)?(pidele|pedile|solicitale|solicita al cliente|pide al cliente|pidele al cliente)\b/u', $text) === 1) {
            return $this->prepare('prepare_document_request', $available);
        }
        if (preg_match('/\b(prepara|preparar|arma|armar|crea|crear) (una |la )?propuesta\b|\b(puedes |podrias )?pagar (estas|las|esas) (facturas|obligaciones)\b|\bpaga (estas|las|esas)\b/u', $text) === 1) {
            return $this->prepare('prepare_payment_proposal', $available);
        }

        // Memory of the conversation: a short follow-up that names no topic ("¿y los vencidos?", "dame más detalle", "¿por qué?",
        // "¿y el mes pasado?") is about the tool that answered the previous question, with the new period when it names one.
        if ($previousTool !== null && isset($available[$previousTool]) && $this->isFollowUp($text) && $this->score($text, $available)[1] < 2) {
            $arguments = $this->arguments($previousTool, $text, $message);
            if (isset($arguments['period']) && ! self::namesPeriod($text) && isset($previousArguments['period'])) {
                $arguments['period'] = $previousArguments['period'];
            }

            return ['kind' => 'read', 'tool' => $previousTool, 'arguments' => $arguments, 'critical' => null, 'source' => 'memory'];
        }

        // Several questions in one message ("¿cuánto vendí y cuánto IVA pago?"): each part that clearly names its own topic is
        // answered by its tool, up to three.
        $parts = $this->parts($text, $message, $available);
        if (count($parts) >= 2) {
            return ['kind' => 'read_many', 'tool' => $parts[0]['tool'], 'arguments' => $parts[0]['arguments'], 'critical' => null, 'source' => 'rules', 'tools' => $parts];
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
        $text = trim($text, " \t\n¿¡?!.");
        if (mb_strlen($text) > 70) {
            return false;
        }

        return preg_match('/^y (el |la |los |las |de |del |en |con |tambien |entonces )?/u', $text) === 1 && mb_strlen($text) <= 45
            || preg_match('/\b(eso|esto|esos|esas|ese|esa|mas detalle|mas informacion|dame detalle|el detalle|explicame|explicamelo|por que|cuales son|cuales|de quien|de cual|cuanto es|cuanto son|y que mas)\b/u', $text) === 1
            || preg_match('/^(y |ahora |igual |lo mismo |compara(lo)? (con )?)?(el |la |en |de |del |para )?(mes pasado|mes anterior|ano pasado|este ano|este mes|trimestre|'.implode('|', LuzmaPeriod::MONTHS).')\b/u', $text) === 1;
    }

    /** Whether the words name a period ("octubre", "el año pasado", "este trimestre", "2025"). */
    public static function namesPeriod(string $text): bool
    {
        return preg_match('/\b(mes|ano|anual|acumulado|trimestre|ultimos|20\d\d|'.implode('|', LuzmaPeriod::MONTHS).')\b/u', $text) === 1;
    }

    /**
     * The parts of a message that ask different things, each with the tool that answers it.
     *
     * @param  array<string, LuzmaTool>  $available
     * @return list<array{tool: string, arguments: array<string, mixed>}>
     */
    private function parts(string $text, string $original, array $available): array
    {
        $pieces = preg_split('/\?|;|\s+y\s+(?=(cuanto|cuantos|cuantas|que|como|cual|cuales|donde|quien|dame|muestrame|el |la |los |las |mis |mi |tambien))|,\s*(?=(cuanto|que|como|cual|dame|muestrame|y ))/u', $text) ?: [];
        $parts = [];
        foreach ($pieces as $piece) {
            $piece = trim((string) $piece);
            if (mb_strlen($piece) < 6) {
                continue;
            }
            [$tool, $score, $tie] = $this->score($piece, $available);
            if ($tool === null || $score < 2 || $tie || in_array($tool, array_column($parts, 'tool'), true)) {
                continue;
            }
            // Each part keeps the period of the whole message when it does not name its own ("¿cuánto vendí y gasté en octubre?").
            $parts[] = ['tool' => $tool, 'arguments' => $this->arguments($tool, $piece, self::namesPeriod($piece) ? $piece : $original)];
        }

        return array_slice($parts, 0, 3);
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
        // "¿Cómo se prepara el estado de resultados?" asks the law, not the figures.
        if (isset($available['search_normative']) && preg_match('/\b(como se prepara|como se elabora|como se hace|que dice|segun la ley|que exige|niif|nic \d|articulo|decreto)\b/u', $text) === 1) {
            $scores['search_normative'] = ($scores['search_normative'] ?? 0) + 4;
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
        if ($tool !== null && in_array($tool, LuzmaToolRegistry::PERIOD_TOOLS, true)) {
            return ['period' => $original];
        }
        if ($tool === 'find_anomalies') {
            return ['text' => $original];
        }

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
        $arguments = in_array($tool->name, LuzmaToolRegistry::PERIOD_TOOLS, true) ? ['period' => $message] : array_intersect_key($plan['arguments'], $tool->parameters);

        return ['confidence' => (float) $plan['confidence'], 'route' => ['kind' => 'read', 'tool' => $tool->name, 'arguments' => array_map(fn ($value) => is_scalar($value) ? $value : null, $arguments), 'critical' => null, 'source' => 'model']];
    }
}
