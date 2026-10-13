<?php

/*
 * Service hours: outside them every request (except the exempt paths and Atlantia staff) gets the "after hours" page.
 * Off by default so local development is never locked; production turns it on with SERVICE_HOURS_ENABLED=true.
 */
return [
    'enabled' => (bool) env('SERVICE_HOURS_ENABLED', false),

    /** Close the platform right now, whatever the hour (manual shutdown). */
    'force_closed' => (bool) env('SERVICE_HOURS_FORCE_CLOSED', false),

    'timezone' => env('SERVICE_HOURS_TIMEZONE', 'America/Guatemala'),
    'opens_at' => env('SERVICE_HOURS_OPENS_AT', '06:00'),
    'closes_at' => env('SERVICE_HOURS_CLOSES_AT', '23:00'),

    /** Paths that stay reachable out of hours: health checks, payment webhooks, the public API and the sign-in flow. */
    'exempt' => [
        'up',
        'webhooks/*',
        'api/*',
        'calendar/*',
        'login',
        'logout',
        'two-factor-challenge',
        'forgot-password',
        'reset-password/*',
    ],
];
