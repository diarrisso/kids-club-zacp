<?php

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('creates a service via POST', function () {
    $this->actingAs($this->makeTenantUser())
        ->post('http://testtenant.masinga-booking.test/leistungen', [
            'name' => 'Erstuntersuchung Kind',
            'duration_minutes' => 45,
            'color' => '#FF6B6B',
            'description' => 'Erste Untersuchung bis 6 Jahre',
            'is_active' => true,
        ])
        ->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(Service::where('name', 'Erstuntersuchung Kind')->exists())->toBeTrue();
});

it('rejects negative durations', function () {
    $this->actingAs($this->makeTenantUser())
        ->post('http://testtenant.masinga-booking.test/leistungen', [
            'name' => 'Test',
            'duration_minutes' => -5,
            'color' => '#000000',
        ])
        ->assertSessionHasErrors('duration_minutes');
});

it('attaches practitioners to a service', function () {
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://testtenant.masinga-booking.test/leistungen', [
            'name' => 'Prophylaxe',
            'duration_minutes' => 30,
            'color' => '#000000',
            'practitioner_ids' => [$p1->id, $p2->id],
        ])
        ->assertRedirect();

    tenancy()->initialize($this->tenant);
    $service = Service::where('name', 'Prophylaxe')->first();
    expect($service->practitioners)->toHaveCount(2);
});
