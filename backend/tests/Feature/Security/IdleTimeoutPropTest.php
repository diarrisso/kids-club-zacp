<?php

/**
 * Wiring guard for the idle-timeout monitor: the client threshold is driven by
 * the shared Inertia prop auth.idle_timeout_minutes (config/session_idle.php),
 * with a max(1, ...) floor so an invalid/0 config can't produce a 0ms timer.
 */

use App\Models\User;

it('shares the idle timeout threshold as an Inertia auth prop for staff', function () {
    $this->actingAs(User::factory()->create())
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.idle_timeout_minutes', 15));
});

it('reflects a configured threshold', function () {
    config(['session_idle.seuil_minutes' => 5]);

    $this->actingAs(User::factory()->create())
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.idle_timeout_minutes', 5));
});

it('floors an invalid zero threshold to 1 minute (no 0ms timer)', function () {
    config(['session_idle.seuil_minutes' => 0]);

    $this->actingAs(User::factory()->create())
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.idle_timeout_minutes', 1));
});
