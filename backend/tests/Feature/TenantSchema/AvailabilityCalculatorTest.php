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
        'starts_at' => CarbonImmutable::parse($monday->toDateString().' 09:30', 'Europe/Berlin')->setTimezone('UTC'), 'ends_at' => CarbonImmutable::parse($monday->toDateString().' 10:30', 'Europe/Berlin')->setTimezone('UTC'), 'type' => 'block',
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
        'starts_at' => CarbonImmutable::parse($monday->toDateString().' 10:00', 'Europe/Berlin')->setTimezone('UTC'), 'ends_at' => CarbonImmutable::parse($monday->toDateString().' 10:30', 'Europe/Berlin')->setTimezone('UTC'), 'status' => 'confirmed',
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
        'starts_at' => CarbonImmutable::parse($monday->toDateString().' 09:00', 'Europe/Berlin')->setTimezone('UTC'), 'ends_at' => CarbonImmutable::parse($monday->toDateString().' 09:30', 'Europe/Berlin')->setTimezone('UTC'), 'status' => 'cancelled',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s, $monday->startOfDay(), $monday->endOfDay());

    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '09:30']);
});

it('returns no slots on a german public holiday but slots on a normal day', function () {
    // Freeze "now" so the holiday sits inside the default 60-day horizon and the
    // lead time, making the test deterministic regardless of when it runs.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 08:00:00', 'Europe/Berlin'));

    // 2026-12-25 (Christmas, German public holiday) is a Friday = ISO day 5.
    // 2026-12-18 is the Friday one week earlier and is NOT a holiday.
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 5,
        'start_time' => '09:00', 'end_time' => '12:00',
    ]);

    $christmas = CarbonImmutable::parse('2026-12-25', 'Europe/Berlin');
    $normalFriday = CarbonImmutable::parse('2026-12-18', 'Europe/Berlin');

    $holidaySlots = makeCalc()->forPractitionerService($p, $s, $christmas->startOfDay(), $christmas->endOfDay());
    $normalSlots  = makeCalc()->forPractitionerService($p, $s, $normalFriday->startOfDay(), $normalFriday->endOfDay());

    expect($holidaySlots)->toBeEmpty();
    expect($normalSlots)->not->toBeEmpty();

    CarbonImmutable::setTestNow();
});
