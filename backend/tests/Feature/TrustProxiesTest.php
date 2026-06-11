<?php

use Illuminate\Support\Facades\Route;

it('resolves the real client ip from x-forwarded-for', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertContent('203.0.113.7');
});

it('treats x-forwarded-proto https as a secure request', function () {
    Route::get('/_test/secure', fn () => request()->isSecure() ? 'secure' : 'insecure');

    // assertSee('secure') would also match the substring inside "insecure",
    // so assert the exact body to make the red/green states genuinely distinct.
    $this->get('/_test/secure', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertContent('secure');
});

it('ignores x-forwarded-host so a client cannot poison the request host', function () {
    Route::get('/_test/host', fn () => request()->getHost());

    // Cloudflare passes X-Forwarded-Host through UNTOUCHED, so it must never be
    // trusted: a client-supplied value would poison every absolute URL generated
    // during the request (e.g. cancellation links in confirmation emails).
    // Baseline = the real host with no forwarded headers; the env determines it
    // (APP_URL), so compare against it instead of hardcoding a hostname.
    $realHost = $this->get('/_test/host')->assertOk()->getContent();

    expect($realHost)->not->toBe('evil.example');

    // X-Forwarded-For is sent too, proving proxy trust is active for this request.
    $this->get('/_test/host', [
        'X-Forwarded-For' => '203.0.113.7',
        'X-Forwarded-Host' => 'evil.example',
    ])
        ->assertOk()
        ->assertContent($realHost);
});
