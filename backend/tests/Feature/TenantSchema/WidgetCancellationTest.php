<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('cancels an appointment by token and frees the slot', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);

    $base = 'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments';

    $this->getJson("{$base}/{$a->cancellation_token}")
        ->assertOk()->assertJsonFragment(['status' => 'confirmed']);

    $this->postJson("{$base}/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    tenancy()->initialize($this->tenant);
    expect($a->fresh()->status)->toBe('cancelled');
});

it('returns 404 for an unknown cancellation token', function () {
    $this->getJson('http://central.masinga-booking.test/api/v1/widget/testtenant/appointments/'.\Illuminate\Support\Str::uuid())
        ->assertNotFound();
});
