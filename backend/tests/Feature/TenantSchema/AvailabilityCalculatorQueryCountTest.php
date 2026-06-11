<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('queries the availabilities table once regardless of the window width', function () {
    $practitioner = Practitioner::factory()->create();
    $service = Service::factory()->create(['duration_minutes' => 30]);

    // A weekly availability on every weekday (Mon-Fri), 09:00-17:00.
    foreach (range(1, 5) as $dayOfWeek) {
        Availability::factory()->create([
            'practitioner_id' => $practitioner->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
    }

    $calc = app(AvailabilityCalculator::class);
    $from = CarbonImmutable::now()->addDays(3)->startOfDay();
    $to = $from->addDays(40); // wide window — ~40 queries under the N+1

    $count = 0;
    DB::listen(function ($q) use (&$count) {
        // Match only the "availabilities" table, never "availability_exceptions":
        // the closing quote right after `availabilities` disambiguates the two.
        if (preg_match('/\bfrom\s+"availabilities"/i', $q->sql)) {
            $count++;
        }
    });

    $calc->forPractitionerService($practitioner, $service, $from, $to);

    expect($count)->toBe(1); // hoisted out of the day-loop
});
