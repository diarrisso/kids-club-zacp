<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

it('redirects guests away from the calendar', function () {
    $this->get('/termine')->assertRedirect();
});

it('rejects guest writes on the appointment mutation routes', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    // Unauthenticated JSON requests must be rejected (401), never executed.
    $this->postJson('/termine', [])->assertUnauthorized();
    $this->patchJson("/termine/{$a->id}", ['starts_at' => $start->addHour()->format('Y-m-d H:i:s')])->assertUnauthorized();
    $this->deleteJson("/termine/{$a->id}")->assertUnauthorized();

    expect($a->fresh()->status)->toBe('confirmed');
});
