<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Desktop app downloads
    |--------------------------------------------------------------------------
    |
    | Where the installers built by .github/workflows/desktop.yml are published (for example public/downloads on this
    | server). Empty hides the download button for that system.
    */
    'downloads' => [
        'windows' => env('DESKTOP_WINDOWS_URL'),
        'mac' => env('DESKTOP_MAC_URL'),
    ],
];
