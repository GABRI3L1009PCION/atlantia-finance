<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The public API authenticates with Bearer keys and is meant for servers, so
| browsers on other sites get no CORS access unless their origin is listed in
| CORS_ALLOWED_ORIGINS (comma separated, e.g. https://erp.example.com).
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];
