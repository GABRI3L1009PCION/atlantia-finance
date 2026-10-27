<?php

/*
|--------------------------------------------------------------------------
| Alerts to the platform team's phones
|--------------------------------------------------------------------------
|
| The health checks run every minute. When a watched service fails twice in a row, or the disk passes the warning
| level, an alert goes to every channel configured here, and another when it recovers. Without channels nothing is
| sent and the alerts only stay in the control center.
|
| - Telegram: create a bot with @BotFather (gives the token) and write it once from the phone; the chat id is the
|   "chat.id" that https://api.telegram.org/bot<token>/getUpdates shows.
| - ntfy: install the ntfy app and subscribe to a long, hard-to-guess topic, e.g. https://ntfy.sh/atlantia-7f3k9q.
| - Heartbeat: when the whole server is off nobody inside it can warn. A free check on healthchecks.io receives a
|   ping every minute and alerts the phone itself when the pings stop.
|
*/

return [
    'telegram' => [
        'bot_token' => env('MONITORING_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('MONITORING_TELEGRAM_CHAT_ID'),
    ],

    'ntfy_url' => env('MONITORING_NTFY_URL'),

    'email' => env('MONITORING_EMAIL'),

    'heartbeat_url' => env('MONITORING_HEARTBEAT_URL'),

    // Services whose failure is worth waking someone up.
    'services' => ['app', 'database', 'queues', 'scheduler', 'storage', 'mail', 'fel'],

    // Consecutive failed checks (one per minute) before alerting, so a single slow answer does not ring the phone.
    'confirm_checks' => 2,

    // While a service stays down the alert is repeated this often.
    'repeat_minutes' => 60,

    'disk_warning_percent' => 85,

    'disk_critical_percent' => 95,

    // The queue heartbeat job must be processed within this time, or the queue is considered stopped.
    'queue_stalled_seconds' => 300,
];
