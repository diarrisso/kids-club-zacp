<?php
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\AvailabilityException;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;

function makeCalc(): AvailabilityCalculator
{
    return app(AvailabilityCalculator::class);
}

// A Monday comfortably within the 60-day horizon and beyond the 2h lead time.
function bookableMonday(): CarbonImmutable
{
    return CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
}

it('generates duration-aligned slots within an availability', function () {
    $monday = bookableMonday();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s, $monday->startOfDay(), $monday->endOfDay());

    expect($slots->pluck('starts_at')->map->format('H:i')->all())
        ->toBe(['09:00', '09:30', '10:00', '10:30']);
});

it('removes slots overlapping an availability exception', function () {
    $monday = bookableMonday();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);
    AvailabilityException::factory()->create([
        'practitioner_id' => $p->id,
        'starts_at' => $monday->setTime(9, 30), 'ends_at' => $monday->setTime(10, 30), 'type' => 'block',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s, $monday->startOfDay(), $monday->endOfDay());

    // 09:30 and 10:00 overlap the block; 09:00 and 10:30 survive
    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '10:30']);
});

it('removes slots overlapping an existing appointment', function () {
    $monday = bookableMonday();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $monday->setTime(10, 0), 'ends_at' => $monday->setTime(10, 30), 'status' => 'confirmed',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s, $monday->startOfDay(), $monday->endOfDay());

    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '09:30', '10:30']);
});

it('ignores cancelled appointments when computing slots', function () {
    $monday = bookableMonday();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $monday->setTime(9, 0), 'ends_at' => $monday->setTime(9, 30), 'status' => 'cancelled',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s, $monday->startOfDay(), $monday->endOfDay());

    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '09:30']);
});
