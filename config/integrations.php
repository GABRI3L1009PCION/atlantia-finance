<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hidden connections and Public API
    |--------------------------------------------------------------------------
    |
    | hidden_providers lists the firm connections that are built and tested but not offered yet (comma separated in
    | INTEGRATIONS_HIDDEN_PROVIDERS; empty shows all). Requests allowed per minute and per key, maximum page size and how long
    | the request log is kept.
    |
    */

    'hidden_providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('INTEGRATIONS_HIDDEN_PROVIDERS', 'gmail,outlook,dropbox,hubspot'))))),

    'api' => [
        'rate_limit_per_minute' => (int) env('INTEGRATIONS_API_RATE_LIMIT', 120),
        'max_per_page' => 100,
        'log_retention_days' => (int) env('INTEGRATIONS_LOG_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing webhooks
    |--------------------------------------------------------------------------
    |
    | Deliveries are signed with the endpoint secret and retried with growing
    | waits (seconds). After `disable_after_failures` consecutive failures the
    | endpoint is disabled and its creator notified. Plain http:// URLs and
    | private network targets are only accepted when `allow_insecure_urls` is
    | true (local development). `resolve_dns` checks where the host points to
    | block internal network targets.
    |
    */

    'webhooks' => [
        'timeout_seconds' => (int) env('INTEGRATIONS_WEBHOOK_TIMEOUT', 10),
        'retry_delays' => [60, 300, 1800, 7200, 21600, 43200],
        'disable_after_failures' => (int) env('INTEGRATIONS_WEBHOOK_DISABLE_AFTER', 15),
        'allow_insecure_urls' => (bool) env('INTEGRATIONS_ALLOW_INSECURE_WEBHOOKS', false),
        'resolve_dns' => (bool) env('INTEGRATIONS_WEBHOOK_RESOLVE_DNS', true),
    ],

];
