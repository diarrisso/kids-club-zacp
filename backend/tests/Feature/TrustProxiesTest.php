<?php

use Illuminate\Support\Facades\Route;

it('resolves the real client ip from x-forwarded-for', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertSee('203.0.113.7');
});

it('treats x-forwarded-proto https as a secure request', function () {
    Route::get('/_test/secure', fn () => request()->isSecure() ? 'secure' : 'insecure');

    // assertSee('secure') would also match the substring inside "insecure",
    // so assert the exact body to make the red/green states genuinely distinct.
    $this->get('/_test/secure', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertContent('secure');
});
