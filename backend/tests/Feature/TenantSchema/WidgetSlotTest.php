<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

it('merges slots from all practitioners offering the service, each tagged with its practitioner', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Berg', 'color' => '#98ACBA']);
    $p2 = Practitioner::factory()->create(['first_name' => 'Tom', 'last_name' => 'Adler', 'color' => '#F7E29D']);
    $s->practitioners()->attach([$p1->id, $p2->id]);

    Availability::factory()->create([
        'practitioner_id' => $p1->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    Availability::factory()->create([
        'practitioner_id' => $p2->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonStructure([['starts_at', 'ends_at', 'practitioner' => ['id', 'first_name', 'last_name', 'color']]])
        ->assertJsonCount(4) // 2 practitioners × (09:00, 09:30)
        // secondary sort: within each starts_at, last_name asc → Adler before Berg
        ->assertJsonPath('0.practitioner.last_name', 'Adler')
        ->assertJsonPath('2.practitioner.last_name', 'Adler');
});

it('excludes inactive practitioners from the all-practitioners merge', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $inactive = Practitioner::factory()->create(['is_active' => false]);
    $s->practitioners()->attach([$p1->id, $p2->id, $inactive->id]);

    foreach ([$p1, $p2, $inactive] as $p) {
        Availability::factory()->create([
            'practitioner_id' => $p->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '10:00',
        ]);
    }

    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonCount(4); // only the 2 active practitioners × 2 slots
});

it('returns an empty array when an inactive practitioner_id is requested', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $inactive = Practitioner::factory()->create(['is_active' => false]);
    $s->practitioners()->attach($inactive->id);
    Availability::factory()->create([
        'practitioner_id' => $inactive->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $s->id,
        'practitioner_id' => $inactive->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonCount(0); // inactive practitioner contributes nothing
});

it('restricts to one practitioner when practitioner_id is given', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $s->practitioners()->attach([$p1->id, $p2->id]);
    Availability::factory()->create(['practitioner_id' => $p1->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);
    Availability::factory()->create(['practitioner_id' => $p2->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $s->id,
        'practitioner_id' => $p1->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonCount(2) // only p1's two slots
        ->assertJsonPath('0.practitioner.id', $p1->id);
});

it('returns free slots for a practitioner and service', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/slots?'
        .http_build_query([
            'practitioner_id' => $p->id, 'service_id' => $s->id,
            'from' => $monday->toDateString(), 'to' => $monday->toDateString(),
        ]))
        ->assertOk()
        ->assertJsonStructure([['starts_at', 'ends_at']])
        ->assertJsonCount(2); // 09:00, 09:30
});

// Fix 0/2: clinic-tz parsing — a single-day request must NOT spill into the next Berlin day
it('does not return slots from the next day when the range is a single day', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
    $tuesday = $monday->addDay();

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p = Practitioner::factory()->create();
    $s->practitioners()->attach($p->id);

    // Monday 09:00–10:00 (2 slots)
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    // Tuesday 09:00–10:00 (2 slots) — must NOT appear in a Monday-only request
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 2,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $response = $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))->assertOk();

    $response->assertJsonCount(2); // only Monday's 2 slots

    // No slot's starts_at should fall on Tuesday
    collect($response->json())->each(function ($slot) use ($tuesday) {
        expect($slot['starts_at'])->not->toContain($tuesday->toDateString());
    });
});

// Fix 1: explicit practitioner_id must be scoped to the service
it('returns empty slots when practitioner_id is active but not attached to the service', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $unrelated = Practitioner::factory()->create(); // active but NOT attached to $s
    Availability::factory()->create([
        'practitioner_id' => $unrelated->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $s->id,
        'practitioner_id' => $unrelated->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonCount(0); // no slots — practitioner not offering this service
});
