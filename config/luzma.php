<?php

/*
 * Luzma, the internal assistant of Atlantia Finance. The model runs locally (FastAPI gateway + Ollama); Laravel
 * resolves the context, authorizes and computes every figure. Luzma being offline never stops accounting.
 */
return [
    'enabled' => (bool) env('LUZMA_ENABLED', true),

    /*
     * Model providers in order of preference, comma separated. Only the local "ollama" gateway exists: Luzma never sends
     * data to an external AI service, so any other name is ignored.
     */
    'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('LUZMA_PROVIDERS', 'ollama'))))),

    'gateway_url' => env('LUZMA_URL', env('ATLANTIA_AI_URL', 'http://127.0.0.1:8100')),

    /*
     * Command the watchdog runs to bring Ollama and the gateway back when they stop answering. On Windows it defaults to
     * the start script of the gateway; on Linux the gateway runs under systemd (Restart=always) and this stays empty.
     */
    'restart_command' => env('LUZMA_RESTART_COMMAND'),

    'timeouts' => [
        'health' => (int) env('LUZMA_HEALTH_TIMEOUT', 4),
        'plan' => (int) env('LUZMA_PLAN_TIMEOUT', 45),
        'answer' => (int) env('LUZMA_ANSWER_TIMEOUT', 120),
    ],

    'features' => [
        'contextual' => (bool) env('LUZMA_CONTEXTUAL', true),
        'proactive_insights' => (bool) env('LUZMA_PROACTIVE_INSIGHTS', true),
        'reminders' => (bool) env('LUZMA_REMINDERS', true),
        'actions' => (bool) env('LUZMA_ACTIONS', true),
    ],

    /** Prepared actions expire so an old preview can never be confirmed against changed data. */
    'action_ttl_minutes' => (int) env('LUZMA_ACTION_TTL', 30),

    /** Conversation turns sent back to the model as memory. */
    'history_turns' => 6,
];
