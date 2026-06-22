<?php

use App\Models\Tenant\Appointment;
use App\Support\Attendance;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('casts the attendance column to the Attendance enum', function () {
    $appointment = Appointment::factory()->create(['attendance' => 'no_show']);

    expect($appointment->fresh()->attendance)->toBe(Attendance::NoShow);
});

it('defaults attendance to null for a fresh appointment', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->fresh()->attendance)->toBeNull();
});

it('exposes German labels via the enum', function () {
    expect(Attendance::Arrived->label())->toBe('Erschienen')
        ->and(Attendance::NoShow->label())->toBe('Nicht erschienen');
});
