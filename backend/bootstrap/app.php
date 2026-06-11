<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecureHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Production sits behind Cloudflare -> nginx. Trusting the proxy is what
        // makes $request->ip() return the real client (all per-IP rate limiters
        // depend on it) and X-Forwarded-Proto produce https URLs. `at: '*'` is
        // only safe because the VPS firewall accepts 80/443 exclusively from
        // Cloudflare ranges (ops checklist in the PR-B spec) — a direct-to-origin
        // caller could otherwise spoof X-Forwarded-For. That firewall argument
        // covers XFF (Cloudflare appends the real IP) and XFP (Cloudflare
        // overwrites it) — but Cloudflare passes X-Forwarded-Host/Port through
        // UNTOUCHED, so trusting them would let any client poison
        // $request->getHost() and every absolute URL generated during the
        // request (e.g. cancellation links in booking-confirmation emails).
        // They are deliberately NOT in the bitmask: nginx forwards the true
        // Host header itself, and with proto=https the port defaults to 443.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecureHeaders::class,
        ]);

        $middleware->alias([
            'two-factor.enrolled' => EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
