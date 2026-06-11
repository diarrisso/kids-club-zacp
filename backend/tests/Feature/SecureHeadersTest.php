<?php

it('sends the security headers on web responses', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});

it('omits hsts on insecure requests but sends it on https', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('omits the csp outside production', function () {
    $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
});

it('enforces a strict csp in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self'")            // regression guard: never 'unsafe-inline' scripts
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("img-src 'self' data: blob:")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'");
});
