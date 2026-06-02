<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

it('reschedules an appointment to a new slot (drag&drop)', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    $new = $start->addHour();
    $this->actingAs($user)->patchJson("/termine/{$a->id}", [
        'starts_at' => $new->format('Y-m-d H:i:s'),
        'ends_at' => $new->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertOk();

    // Stored wall-clock should be the new 10:00 Berlin time.
    expect($a->fresh()->starts_at->format('Y-m-d H:i'))->toBe($new->format('Y-m-d H:i'));
});

it('rejects a reschedule onto another appointment of the same practitioner (409)', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start->addHour(), 'ends_at' => $start->addHour()->addMinutes(30), 'status' => 'confirmed',
    ]);

    $this->actingAs($user)->patchJson("/termine/{$a->id}", [
        'starts_at' => $start->addHour()->format('Y-m-d H:i:s'),
        'ends_at' => $start->addHour()->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertStatus(409);
});
