<?php

use App\Models\Tenant\Service;

it('resolves the tenant from the path', function () {
    Service::factory()->create(['name' => 'Erstuntersuchung', 'is_active' => true]);

    $this->getJson('http://central.masinga-booking.test/api/v1/widget/testtenant/services')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Erstuntersuchung']);
});

it('returns 404 for an unknown tenant slug', function () {
    $this->getJson('http://central.masinga-booking.test/api/v1/widget/does-not-exist/services')
        ->assertNotFound();
});
