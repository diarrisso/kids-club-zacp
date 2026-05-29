<?php

use App\Models\Tenant\AvailabilityException;
use App\Models\Tenant\Practitioner;

it('creates a vacation exception spanning multiple days', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://testtenant.masinga-booking.test/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-15 23:59:59',
            'type' => 'vacation',
            'reason' => 'Sommerurlaub',
        ])
        ->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(AvailabilityException::count())->toBe(1);
});

it('rejects ends_at before starts_at', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://testtenant.masinga-booking.test/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-08-15 00:00:00',
            'ends_at' => '2026-08-01 23:59:59',
            'type' => 'vacation',
        ])
        ->assertSessionHasErrors('ends_at');
});
