<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AvailabilityCalculator
{
    public const LEAD_MINUTES = 120;   // 2h minimum lead time
    public const HORIZON_DAYS = 60;    // book up to 60 days ahead

    /** @return Collection<int, Slot> */
    public function forPractitionerService(
        Practitioner $practitioner,
        Service $service,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $duration = $service->duration_minutes;
        $slots = collect();

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $dow = $day->dayOfWeekIso; // 1 = Monday ... 7 = Sunday

            $availabilities = $practitioner->availabilities()
                ->where('day_of_week', $dow)
                ->get();

            foreach ($availabilities as $availability) {
                $slots = $slots->merge(
                    $this->slotsForDay($day, $availability->start_time, $availability->end_time, $duration)
                );
            }
        }

        return $slots->values();
    }

    /** @return Collection<int, Slot> */
    private function slotsForDay(CarbonImmutable $day, CarbonInterface $start, CarbonInterface $end, int $duration): Collection
    {
        $slots = collect();
        $cursor = $day->setTime((int) $start->format('H'), (int) $start->format('i'));
        $dayEnd = $day->setTime((int) $end->format('H'), (int) $end->format('i'));

        while ($cursor->addMinutes($duration)->lessThanOrEqualTo($dayEnd)) {
            $slots->push(new Slot($cursor, $cursor->addMinutes($duration)));
            $cursor = $cursor->addMinutes($duration);
        }

        return $slots;
    }
}
