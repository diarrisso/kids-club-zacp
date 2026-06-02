<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('allows an appointment with no parent_email and no consent (manual booking)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'parent_email' => null, 'parent_consent_at' => null,
    ]);

    expect($a->fresh())
        ->parent_email->toBeNull()
        ->parent_consent_at->toBeNull();
});
