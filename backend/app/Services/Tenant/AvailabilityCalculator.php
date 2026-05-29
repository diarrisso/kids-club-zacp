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

        $earliest = CarbonImmutable::now()->addMinutes(self::LEAD_MINUTES);
        $latest = CarbonImmutable::now()->addDays(self::HORIZON_DAYS);
        $from = $from->greaterThan($earliest) ? $from : $earliest;
        $to = $to->lessThan($latest) ? $to : $latest;

        if ($from->greaterThan($to)) {
            return collect();
        }

        $exceptions = $practitioner->availabilityExceptions()
            ->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->get();

        $appointments = $practitioner->appointments()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->get();

        $slots = collect();
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $availabilities = $practitioner->availabilities()
                ->where('day_of_week', $day->dayOfWeekIso)->get();

            foreach ($availabilities as $availability) {
                foreach ($this->slotsForDay($day, $availability->start_time, $availability->end_time, $duration) as $slot) {
                    if ($slot->starts_at->lessThan($earliest)) {
                        continue;
                    }
                    if ($this->overlapsAny($slot, $exceptions) || $this->overlapsAny($slot, $appointments)) {
                        continue;
                    }
                    $slots->push($slot);
                }
            }
        }

        return $slots->values();
    }

    /** @param \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $intervals */
    private function overlapsAny(Slot $slot, Collection $intervals): bool
    {
        foreach ($intervals as $i) {
            if ($slot->starts_at->lessThan($i->ends_at) && $slot->ends_at->greaterThan($i->starts_at)) {
                return true;
            }
        }

        return false;
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
