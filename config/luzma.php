<?php

/*
 * Luzma, the internal assistant of Atlantia Finance. The model runs locally (FastAPI gateway + Ollama); Laravel
 * resolves the context, authorizes and computes every figure. Luzma being offline never stops accounting.
 */
return [
    'enabled' => (bool) env('LUZMA_ENABLED', true),

    'gateway_url' => env('LUZMA_URL', env('ATLANTIA_AI_URL', 'http://127.0.0.1:8100')),

    'timeouts' => [
        'health' => (int) env('LUZMA_HEALTH_TIMEOUT', 4),
        'plan' => (int) env('LUZMA_PLAN_TIMEOUT', 25),
        'answer' => (int) env('LUZMA_ANSWER_TIMEOUT', 90),
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
