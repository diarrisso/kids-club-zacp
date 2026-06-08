<?php

use App\Models\Setting;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;

it('clamps the horizon to the booking.horizon_days setting', function () {
    Setting::put('booking.horizon_days', '3');

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);

    // A weekly availability that only becomes valid 30 days out (valid_from),
    // so it can only be reached if the horizon extends past 3 days.
    $far = CarbonImmutable::now()->addDays(30);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => $far->dayOfWeekIso,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'valid_from' => $far->toDateString(),
    ]);

    $slots = app(AvailabilityCalculator::class)->forPractitionerService(
        $p, $s,
        CarbonImmutable::now()->startOfDay(),
        CarbonImmutable::now()->addDays(60)->endOfDay(),
    );

    expect($slots)->toHaveCount(0); // horizon=3 never reaches the day-10 availability
});

it('uses the constant horizon by default (no setting row)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);

    $far = CarbonImmutable::now()->addDays(30);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => $far->dayOfWeekIso,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'valid_from' => $far->toDateString(),
    ]);

    $slots = app(AvailabilityCalculator::class)->forPractitionerService(
        $p, $s,
        CarbonImmutable::now()->startOfDay(),
        CarbonImmutable::now()->addDays(60)->endOfDay(),
    );

    expect($slots->count())->toBeGreaterThan(0); // default horizon 60 reaches day 30
});

it('clamps slots to the booking.lead_minutes setting', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);

    // Anchor on a single day 5 days out (well inside the default 60-day horizon and
    // beyond the default 120-min lead) so the test is deterministic regardless of
    // when the suite runs. valid_from == valid_to pins it to that one date so the
    // weekly recurrence cannot resurface past a large lead. Clinic hours 09:00-17:00
    // yield several slots that day.
    $day = CarbonImmutable::now()->addDays(5);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => $day->dayOfWeekIso,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'valid_from' => $day->toDateString(),
        'valid_to' => $day->toDateString(),
    ]);

    $from = CarbonImmutable::now()->startOfDay();
    $to = CarbonImmutable::now()->addDays(60)->endOfDay();

    // Default lead (120 min): the day-5 slots are reachable.
    $default = app(AvailabilityCalculator::class)->forPractitionerService($p, $s, $from, $to);
    expect($default->count())->toBeGreaterThan(0);

    // 40 days of lead pushes the day-5 slots out of bookable range entirely.
    Setting::put('booking.lead_minutes', (string) (60 * 24 * 40));
    $clamped = app(AvailabilityCalculator::class)->forPractitionerService($p, $s, $from, $to);
    expect($clamped)->toHaveCount(0);
});
