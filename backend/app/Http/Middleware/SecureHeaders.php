<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    /**
     * Security response headers. Two profiles:
     *  - web (default): full set incl. clickjacking protection and, in
     *    production, a strict CSP. The embeddable widget is NOT affected —
     *    it runs on the practice's site under the HOST page's CSP; this
     *    header governs only our own pages (staff app, storno, landing).
     *  - api: slim set for JSON/font responses (CSP/XFO are meaningless there
     *    and Referrer-Policy is stricter since API URLs never need a referrer).
     */
    public function handle(Request $request, Closure $next, string $profile = 'web'): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($profile === 'api') {
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (app()->environment('production')) {
            // 'unsafe-inline' styles: Inertia/Tailwind inline style attributes +
            // the storno page's <style> block. Scripts stay strict 'self' — the
            // Vite build emits only hashed external files and Inertia passes page
            // data via a data-page attribute, not inline <script>. blob:/data:
            // images: Appearance logo preview (createObjectURL) and QR previews.
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
