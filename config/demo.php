<?php

declare(strict_types=1);

return [
    /** Permiso explícito y temporal para correr los seeders de demostración de los planes en producción. */
    'allow_production_seed' => (bool) env('ATLANTIA_ALLOW_DEMO_SEED', false),

    'password' => env('ATLANTIA_DEMO_PASSWORD', env('APP_ENV') === 'production' ? null : 'AtlantiaDemo!2026'),
];
