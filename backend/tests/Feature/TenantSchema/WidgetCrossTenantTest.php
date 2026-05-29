<?php
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('does not leak appointments across tenants via the widget API', function () {
    // testtenant (the default) gets one appointment...
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);
    expect(Appointment::count())->toBe(1);
    tenancy()->end();

    // ...a second tenant has its own (empty) schema.
    $other = Tenant::factory()->create(['id' => 'cabinet-x']);
    $other->domains()->create(['domain' => 'cabinet-x.masinga-booking.test', 'is_primary' => true]);

    // The widget API for cabinet-x sees zero services (its schema is empty).
    $this->getJson('http://central.masinga-booking.test/api/v1/widget/cabinet-x/services')
        ->assertOk()->assertJsonCount(0);
});
