<?php

return [
    'provider' => env('FEL_PROVIDER', 'mock'),
    'environment' => env('FEL_ENVIRONMENT', 'test'),
    'api_url' => env('FEL_API_URL'),
    'api_key' => env('FEL_API_KEY'),
    'username' => env('FEL_USERNAME'),
    'password' => env('FEL_PASSWORD'),
    'queue_connection' => env('FEL_QUEUE_CONNECTION', 'database'),
    'queue' => env('FEL_QUEUE', 'default'),
    'subscription_issuer' => env('FEL_SUBSCRIPTION_ISSUER'),
    'platform' => [
        'name' => env('FEL_PLATFORM_NAME', 'Atlantia Systems'),
        'nit' => env('FEL_PLATFORM_NIT', '97655988'),
        'address' => env('FEL_PLATFORM_ADDRESS', 'Colonia la Repegua Santo Tomas de Castilla'),
        'logo' => public_path('images/atlantia-finance-logo.png'),
    ],
    'notice' => 'Documento generado en ambiente de prueba. No constituye certificación FEL ante SAT.',
];
