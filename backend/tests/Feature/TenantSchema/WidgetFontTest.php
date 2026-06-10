<?php

it('serves a whitelisted font with long-lived caching and cors', function () {
    $this->get('/api/v1/widget/fonts/fredoka.woff2', ['Origin' => 'https://praxis-website.example'])
        ->assertOk()
        ->assertHeader('Content-Type', 'font/woff2')
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

it('404s on non-whitelisted files', function () {
    $this->get('/api/v1/widget/fonts/evil.php')->assertNotFound();
    $this->get('/api/v1/widget/fonts/../../.env')->assertNotFound();
});

it('uses the dedicated widget-font limiter, not the shared widget-read bucket', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => str_contains($r->uri(), 'widget/fonts'));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:widget-font')
        ->and($route->gatherMiddleware())->not->toContain('throttle:widget-read');
});
