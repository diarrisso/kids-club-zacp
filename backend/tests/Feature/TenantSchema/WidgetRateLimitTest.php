<?php

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('rate-limits booking attempts', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $url = 'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments';

    // 5 allowed per minute; the 6th must be throttled. (Invalid body is fine — the
    // throttle middleware runs before validation, so each call consumes a token.)
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($url, ['practitioner_id' => $p->id, 'service_id' => $s->id]);
    }
    $this->postJson($url, ['practitioner_id' => $p->id, 'service_id' => $s->id])
        ->assertStatus(429);
});
