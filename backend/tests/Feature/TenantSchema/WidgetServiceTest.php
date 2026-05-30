<?php

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('lists active practitioners for a service', function () {
    $service = Service::factory()->create();
    $active = Practitioner::factory()->create(['is_active' => true]);
    $inactive = Practitioner::factory()->create(['is_active' => false]);
    $service->practitioners()->attach([$active->id, $inactive->id]);

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $active->id]);
});
