<?php

namespace App\Services\Tenant;

use App\Models\Setting;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AvailabilityCalculator
{
    public const LEAD_MINUTES = 120;   // 2h minimum lead time

    public const HORIZON_DAYS = 60;    // book up to 60 days ahead

    public const CLINIC_TIMEZONE = 'Europe/Berlin';

    /** @return Collection<int, Slot> */
    public function forPractitionerService(
        Practitioner $practitioner,
        Service $service,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $duration = $service->duration_minutes;

        $earliest = CarbonImmutable::now()->addMinutes($this->leadMinutes());
        $latest = CarbonImmutable::now()->addDays($this->horizonDays());
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
        for ($day = $from->setTimezone(self::CLINIC_TIMEZONE)->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $availabilities = $practitioner->availabilities()
                ->where('day_of_week', $day->dayOfWeekIso)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
                ->get();

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

    public function isBookable(Practitioner $practitioner, Service $service, CarbonImmutable $startsAt): bool
    {
        if (! $practitioner->is_active || ! $service->is_active) {
            return false;
        }
        if (! $practitioner->services()->whereKey($service->getKey())->exists()) {
            return false;
        }

        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        $now = CarbonImmutable::now();
        if ($startsAt->lessThan($now->addMinutes($this->leadMinutes()))) {
            return false;
        }
        if ($startsAt->greaterThan($now->addDays($this->horizonDays()))) {
            return false;
        }

        $tz = self::CLINIC_TIMEZONE;
        $local = $startsAt->setTimezone($tz);

        $availabilities = $practitioner->availabilities()
            ->where('day_of_week', $local->dayOfWeekIso)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $local))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $local))
            ->get();

        $insideGrid = false;
        foreach ($availabilities as $a) {
            $date = $local->toDateString();
            $winStart = CarbonImmutable::parse("{$date} {$a->start_time->format('H:i')}", $tz);
            $winEnd = CarbonImmutable::parse("{$date} {$a->end_time->format('H:i')}", $tz);
            if ($startsAt->lessThan($winStart) || $endsAt->greaterThan($winEnd)) {
                continue;
            }
            if (((int) $winStart->diffInMinutes($startsAt)) % $service->duration_minutes !== 0) {
                continue; // not aligned to the duration grid
            }
            $insideGrid = true;
            break;
        }
        if (! $insideGrid) {
            return false;
        }

        return ! $practitioner->availabilityExceptions()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function leadMinutes(): int
    {
        return (int) Setting::get('booking.lead_minutes', (string) self::LEAD_MINUTES);
    }

    private function horizonDays(): int
    {
        return (int) Setting::get('booking.horizon_days', (string) self::HORIZON_DAYS);
    }

    /** @param Collection<int, Model> $intervals */
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
        $tz = self::CLINIC_TIMEZONE;
        $date = $day->setTimezone($tz)->toDateString();
        $cursor = CarbonImmutable::parse("{$date} {$start->format('H:i')}", $tz);
        $dayEnd = CarbonImmutable::parse("{$date} {$end->format('H:i')}", $tz);

        $slots = collect();
        while ($cursor->addMinutes($duration)->lessThanOrEqualTo($dayEnd)) {
            $slots->push(new Slot($cursor, $cursor->addMinutes($duration)));
            $cursor = $cursor->addMinutes($duration);
        }

        return $slots;
    }
}
