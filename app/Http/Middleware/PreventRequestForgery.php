<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

/**
 * CSRF protection without the XSRF-TOKEN cookie: the token travels in the page (meta tag and the csrfToken Inertia prop)
 * and the front end sends it as X-CSRF-TOKEN, so no cookie readable by scripts is ever set.
 */
class PreventRequestForgery extends Middleware
{
    /** @var bool */
    protected $addHttpCookie = false;
}
