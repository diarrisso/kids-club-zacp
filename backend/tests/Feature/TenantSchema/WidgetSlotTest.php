<?php
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

it('returns free slots for a practitioner and service', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson("http://central.masinga-booking.test/api/v1/widget/testtenant/slots?"
        . http_build_query([
            'practitioner_id' => $p->id, 'service_id' => $s->id,
            'from' => $monday->toDateString(), 'to' => $monday->toDateString(),
        ]))
        ->assertOk()
        ->assertJsonStructure([['starts_at', 'ends_at']])
        ->assertJsonCount(2); // 09:00, 09:30
});
