<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

it('lists distinct dates with at least one free slot across practitioners', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $s->practitioners()->attach([$p1->id, $p2->id]);

    // p1 works Monday, p2 works Tuesday → both days available, Wednesday not.
    Availability::factory()->create([
        'practitioner_id' => $p1->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    Availability::factory()->create([
        'practitioner_id' => $p2->id, 'day_of_week' => 2,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/availability/days?'.http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->addDays(2)->toDateString(),
    ]))
        ->assertOk()
        ->assertExactJson([
            $monday->toDateString(),
            $monday->addDay()->toDateString(),
        ]);
});

it('excludes dates contributed only by an inactive practitioner', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create(['is_active' => false]);
    $s->practitioners()->attach([$p1->id, $p2->id]);

    // p1 active works Monday, p2 inactive works Tuesday → only Monday available.
    Availability::factory()->create([
        'practitioner_id' => $p1->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    Availability::factory()->create([
        'practitioner_id' => $p2->id, 'day_of_week' => 2,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/availability/days?'.http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->addDays(2)->toDateString(),
    ]))
        ->assertOk()
        ->assertExactJson([$monday->toDateString()]);
});

it('validates that the service exists', function () {
    $this->getJson('/api/v1/widget/availability/days?'.http_build_query([
        'service_id' => 999999,
        'from' => now()->toDateString(),
        'to' => now()->addDay()->toDateString(),
    ]))->assertStatus(422);
});
