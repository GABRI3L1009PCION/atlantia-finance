<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Banco de Guatemala: free SOAP service with the official reference exchange rates (no key). The euro code is looked
    // up in its catalog of currencies unless it is set here.
    'banguat' => [
        'url' => env('BANGUAT_URL', 'https://www.banguat.gob.gt/variables/ws/TipoCambio.asmx'),
        'timeout' => (int) env('BANGUAT_TIMEOUT', 20),
        'eur_code' => env('BANGUAT_EUR_CODE'),
    ],

    'recurrente' => [
        'url' => env('RECURRENTE_API_URL', 'https://app.recurrente.com/api'),
        'secret' => env('RECURRENTE_SECRET_KEY'),
        'webhook_secret' => env('RECURRENTE_WEBHOOK_SECRET'),
        'environment' => env('RECURRENTE_ENVIRONMENT', 'test'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'atlantia_ai' => [
        'url' => env('ATLANTIA_AI_URL', 'http://127.0.0.1:8100'),
    ],

];
