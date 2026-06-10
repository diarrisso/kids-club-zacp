<?php

use App\Models\Tenant\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives a short uppercase reference from the uuid id', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->publicReference())
        ->toMatch('/^KC-[0-9A-F]{6}$/')
        ->and($appointment->publicReference())
        ->toBe('KC-'.strtoupper(substr(str_replace('-', '', (string) $appointment->id), 0, 6)));
});

it('is stable across reads and differs from the cancellation token', function () {
    $appointment = Appointment::factory()->create();
    $fresh = Appointment::findOrFail($appointment->id);

    expect($fresh->publicReference())->toBe($appointment->publicReference())
        ->and($appointment->publicReference())->not->toContain($appointment->cancellation_token);
});
