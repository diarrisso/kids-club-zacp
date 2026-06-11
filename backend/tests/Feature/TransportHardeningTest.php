<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Providers\AppServiceProvider;

it('defaults the session cookie to secure outside local and testing', function () {
    // The config default is computed from APP_ENV at config load; in the test
    // env it must be false (http test client), and the default expression must
    // yield true for production. We assert the production branch directly:
    $default = (require base_path('config/session.php'))['secure'];

    expect($default)->toBeFalse(); // APP_ENV=testing here

    // Simulate the production read of the same expression. Capture + restore the
    // ORIGINAL env inside finally so a throw can't leak APP_ENV=production into
    // later same-process tests.
    $originalGetenv = getenv('APP_ENV') ?: 'testing';
    $originalEnv = $_ENV['APP_ENV'] ?? 'testing';

    try {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $prod = (require base_path('config/session.php'))['secure'];
    } finally {
        putenv('APP_ENV='.$originalGetenv);
        $_ENV['APP_ENV'] = $originalEnv;
    }

    expect($prod)->toBeTrue();
});

it('forces https urls in production', function () {
    app()->detectEnvironment(fn () => 'production');
    // boot() is idempotent today (limiters re-register by key); re-invoked here after the env override.
    (new AppServiceProvider(app()))->boot();

    expect(url('/storno/abc'))->toStartWith('https://');
});

it('drives cors origins from the env with a wildcard fallback', function () {
    expect(config('cors.allowed_origins'))->toBe(['*']); // default

    // Two origins on purpose: with a SINGLE allowed origin, fruitcake/php-cors
    // short-circuits and always emits that origin as ACAO (browser enforces the
    // mismatch), so the header would be "present" even for evil.example. Two
    // origins force the dynamic echo-if-allowed branch — the real multi-domain
    // shape WIDGET_ALLOWED_ORIGINS supports via comma separation.
    config(['cors.allowed_origins' => ['https://praxis.example', 'https://praxis-zweitstandort.example']]);
    $this->getJson('/api/v1/widget/config', ['Origin' => 'https://praxis.example'])
        ->assertHeader('Access-Control-Allow-Origin', 'https://praxis.example');
    $this->getJson('/api/v1/widget/config', ['Origin' => 'https://evil.example'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('declares no-referrer on the storno page so the token cannot leak', function () {
    $practitioner = Practitioner::factory()->create();
    $service = Service::factory()->create();
    $appointment = Appointment::factory()->create([
        'practitioner_id' => $practitioner->id,
        'service_id' => $service->id,
        'status' => 'confirmed',
    ]);

    $this->get(route('storno.show', ['token' => $appointment->cancellation_token]))
        ->assertOk()
        ->assertSee('<meta name="referrer" content="no-referrer">', false);

    // The done view renders at the SAME token-bearing URL (GET when already
    // cancelled, and as the POST response) — the invariant must hold there too.
    $appointment->update(['status' => 'cancelled']);

    $this->get(route('storno.show', ['token' => $appointment->cancellation_token]))
        ->assertOk()
        ->assertSee('Ihr Termin wurde storniert')
        ->assertSee('<meta name="referrer" content="no-referrer">', false);
});
