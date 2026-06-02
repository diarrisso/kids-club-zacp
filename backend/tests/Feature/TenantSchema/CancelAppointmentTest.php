<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use App\Services\Tenant\AppointmentScheduler;
use Carbon\CarbonImmutable;

it('cancels an appointment and frees the slot for re-booking', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    $this->actingAs($user)
        ->deleteJson("/termine/{$a->id}")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    expect($a->fresh()->status)->toBe('cancelled');

    // The freed slot no longer overlaps (cancelled excluded), so a new booking succeeds.
    $fresh = app(AppointmentScheduler::class)->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
        'patient_first_name' => 'Tom', 'patient_last_name' => 'Berg', 'patient_birthdate' => '2018-01-01',
        'parent_first_name' => 'Ben', 'parent_last_name' => 'Berg', 'parent_phone' => '+49 170 1',
    ]);
    expect($fresh->status)->toBe('confirmed');
});
