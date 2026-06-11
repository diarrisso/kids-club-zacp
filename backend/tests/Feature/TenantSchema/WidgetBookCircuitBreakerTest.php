<?php

use App\Models\Tenant\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

// Minimal, deliberately-invalid body. The throttle middleware is outermost and runs
// BEFORE FormRequest validation, so each POST consumes a limiter token regardless of
// whether the request later ends 422. We test the throttle, not the booking.
function circuitBreakerPayload(): array
{
    return [];
}

beforeEach(function () {
    // ThrottleRequests hashes named-limiter keys (md5($limiterName.$limit->key),
    // $shouldHashKeys defaults true), so the global breaker's real cache key is
    // md5('widget-book'.'widget-book-global') — NOT a ":"-joined string. Per-test
    // isolation really comes from CACHE_STORE=array (fresh cache per process); this
    // clears the actual key too so the bucket never bleeds if the store ever changes.
    RateLimiter::clear(md5('widget-book'.'widget-book-global'));
    $this->service = Service::factory()->create();
});

it('trips the global circuit-breaker after 30 bookings across distinct ips', function () {
    // 30 requests from 30 different IPs all pass the per-IP limit (5/min each)
    // but together exhaust the 30/min global bucket; the 31st is 429.
    for ($i = 1; $i <= 30; $i++) {
        $res = $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->postJson('/api/v1/widget/appointments', circuitBreakerPayload());
        expect($res->status())->not->toBe(429); // may be 422 (validation) — just NOT throttled
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->postJson('/api/v1/widget/appointments', circuitBreakerPayload())
        ->assertStatus(429);
});

it('still enforces the per-ip limit of 5 per minute', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/widget/appointments', circuitBreakerPayload());
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/api/v1/widget/appointments', circuitBreakerPayload())
        ->assertStatus(429);
});
