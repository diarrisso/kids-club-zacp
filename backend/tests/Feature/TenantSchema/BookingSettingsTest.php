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

    // A weekly availability that only becomes valid 10 days out (valid_from),
    // so it can only be reached if the horizon extends past 3 days.
    $far = CarbonImmutable::now()->addDays(10);
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

    $far = CarbonImmutable::now()->addDays(10);
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

    expect($slots->count())->toBeGreaterThan(0); // default horizon 60 reaches day 10
});
