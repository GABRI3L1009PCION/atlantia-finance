<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Behind a load balancer (AWS ALB, CloudFront, Nginx) the application only sees the balancer's address and plain
    | HTTP. Naming the balancer here lets Laravel read the real visitor address (rate limits and audit trail) and
    | detect HTTPS (secure cookies, HSTS, the redirect to HTTPS). Use "*" when the balancer is the only way in, or a
    | comma separated list of addresses or CIDR ranges. Empty: no proxy is trusted.
    |
    */

    'proxies' => (function (): string|array|null {
        $value = trim((string) env('TRUSTED_PROXIES', ''));

        return match (true) {
            $value === '' => null,
            $value === '*' => '*',
            default => array_values(array_filter(array_map('trim', explode(',', $value)))),
        };
    })(),

];
