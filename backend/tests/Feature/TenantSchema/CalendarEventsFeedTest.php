<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

function feedUrl(CarbonImmutable $start, CarbonImmutable $end, array $practitionerIds = []): string
{
    $q = ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()];
    foreach ($practitionerIds as $i => $id) {
        $q["practitioner_ids[$i]"] = $id;
    }

    return '/termine/events?'.http_build_query($q);
}

it('returns confirmed appointments within the range', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Berg', 'title' => 'Dr.', 'color' => '#3b82f6']);
    $s = Service::factory()->create(['name' => 'Prophylaxe', 'duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');

    $inRange = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
        'status' => 'confirmed', 'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
    ]);
    // out of range
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start->addDays(30), 'ends_at' => $start->addDays(30)->addMinutes(30), 'status' => 'confirmed',
    ]);
    // cancelled in range -> excluded
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start->addHour(), 'ends_at' => $start->addHour()->addMinutes(30), 'status' => 'cancelled',
    ]);

    $this->actingAs($user)
        ->getJson(feedUrl($start->startOfWeek(), $start->endOfWeek()))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $inRange->id])
        ->assertJsonFragment(['name' => 'Prophylaxe']);
});

it('filters the feed by practitioner', function () {
    $user = User::factory()->create();
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');

    Appointment::factory()->create(['practitioner_id' => $p1->id, 'service_id' => $s->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed']);
    Appointment::factory()->create(['practitioner_id' => $p2->id, 'service_id' => $s->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed']);

    $this->actingAs($user)
        ->getJson(feedUrl($start->startOfWeek(), $start->endOfWeek(), [$p1->id]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $p1->id, 'name' => $p1->fullName()]);
});
