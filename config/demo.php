<?php

declare(strict_types=1);

return [
    'password' => env('ATLANTIA_DEMO_PASSWORD', env('APP_ENV') === 'production' ? null : 'AtlantiaDemo!2026'),
];
