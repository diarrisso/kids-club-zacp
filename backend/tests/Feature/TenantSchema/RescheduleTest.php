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

it('recomputes ends_at from the new service duration when only service_id changes', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $short = Service::factory()->create(['duration_minutes' => 30]);
    $long = Service::factory()->create(['duration_minutes' => 60]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $short->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    // Change only the service (no starts_at / ends_at in the payload): the end
    // must be recomputed from the existing start + the new 60-min duration.
    $this->actingAs($user)->patchJson("/termine/{$a->id}", ['service_id' => $long->id])
        ->assertOk();

    $fresh = $a->fresh();
    expect($fresh->service_id)->toBe($long->id)
        ->and($fresh->starts_at->format('Y-m-d H:i'))->toBe($start->format('Y-m-d H:i'))
        ->and($fresh->ends_at->format('Y-m-d H:i'))->toBe($start->addMinutes(60)->format('Y-m-d H:i'));
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
