<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;

function makeCalc(): AvailabilityCalculator
{
    return app(AvailabilityCalculator::class);
}

it('generates duration-aligned slots within an availability', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    // 2026-09-07 is a Monday (day_of_week = 1)
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);

    $slots = makeCalc()->forPractitionerService(
        $p, $s,
        CarbonImmutable::parse('2026-09-07 00:00'),
        CarbonImmutable::parse('2026-09-07 23:59'),
    );

    expect($slots->pluck('starts_at')->map->format('H:i')->all())
        ->toBe(['09:00', '09:30', '10:00', '10:30']); // last fits 30min before 11:00
});
