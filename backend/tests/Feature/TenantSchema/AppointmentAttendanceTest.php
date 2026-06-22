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

it('updates attendance via the staff PATCH endpoint', function () {
    $user = \App\Models\User::factory()->create(['two_factor_confirmed_at' => now()]);
    $appointment = Appointment::factory()->create();

    $this->actingAs($user)
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'arrived'])
        ->assertOk()
        ->assertJsonPath('attendance', 'arrived');

    expect($appointment->fresh()->attendance)->toBe(Attendance::Arrived);
});

it('clears attendance back to null when sent null', function () {
    $user = \App\Models\User::factory()->create(['two_factor_confirmed_at' => now()]);
    $appointment = Appointment::factory()->create(['attendance' => 'no_show']);

    $this->actingAs($user)
        ->patchJson("/termine/{$appointment->id}", ['attendance' => null])
        ->assertOk()
        ->assertJsonPath('attendance', null);

    expect($appointment->fresh()->attendance)->toBeNull();
});

it('rejects an invalid attendance value with 422', function () {
    $user = \App\Models\User::factory()->create(['two_factor_confirmed_at' => now()]);
    $appointment = Appointment::factory()->create();

    $this->actingAs($user)
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'maybe'])
        ->assertStatus(422);
});

it('never lets the public widget set attendance (mass-assignment guard)', function () {
    [$p, $s, $startsAt] = bookingSetup();

    $response = $this->postJson('/api/v1/widget/appointments', bookingPayload([
        'service_id' => $s->id,
        'practitioner_id' => $p->id,
        'starts_at' => $startsAt->toIso8601String(),
        'attendance' => 'arrived', // hostile field
    ]));

    $response->assertStatus(201);
    $appointment = Appointment::query()->latest('created_at')->first();
    expect($appointment->attendance)->toBeNull();
});
