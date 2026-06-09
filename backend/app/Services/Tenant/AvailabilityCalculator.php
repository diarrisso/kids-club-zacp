<?php

namespace App\Services\Tenant;

use App\Models\Setting;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Yasumi\ProviderInterface;
use Yasumi\Yasumi;

class AvailabilityCalculator
{
    public const LEAD_MINUTES = 120;   // 2h minimum lead time

    public const HORIZON_DAYS = 60;    // book up to 60 days ahead

    public const CLINIC_TIMEZONE = 'Europe/Berlin';

    /** @var array<int, ProviderInterface> */
    private array $holidayProviders = [];

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
            if ($this->isPublicHoliday($day)) {
                continue;
            }

            $availabilities = $practitioner->availabilities()
                ->where('day_of_week', $day->dayOfWeekIso)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
                ->get();

            foreach ($availabilities as $availability) {
                foreach ($this->slotsForDay($day, $availability->start_time, $availability->end_time, $duration, $availability->slot_interval_minutes) as $slot) {
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

    /** @return Collection<int, string> distinct YYYY-MM-DD dates (clinic tz) having >=1 free slot */
    public function availableDates(Service $service, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $dates = collect();

        foreach ($service->practitioners()->active()->get() as $practitioner) {
            foreach ($this->forPractitionerService($practitioner, $service, $from, $to) as $slot) {
                $dates->push($slot->starts_at->setTimezone(self::CLINIC_TIMEZONE)->toDateString());
            }
        }

        return $dates->unique()->sort()->values();
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

        if ($this->isPublicHoliday($local)) {
            return false;
        }

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
            $step = $a->slot_interval_minutes ?? $service->duration_minutes;
            if (((int) $winStart->diffInMinutes($startsAt)) % $step !== 0) {
                continue; // not aligned to the slot grid
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

    private function isPublicHoliday(CarbonImmutable $day): bool
    {
        $year = (int) $day->year;
        if (! isset($this->holidayProviders[$year])) {
            $country = (string) config('booking.country', 'Germany');
            $bundesland = (string) config('booking.bundesland', '');
            $provider = $bundesland !== '' ? "{$country}/{$bundesland}" : $country;
            $this->holidayProviders[$year] = Yasumi::create($provider, $year, 'de_DE');
        }

        return $this->holidayProviders[$year]->isHoliday($day->toDateTimeImmutable());
    }

    private function leadMinutes(): int
    {
        $value = (int) Setting::get('booking.lead_minutes', (string) self::LEAD_MINUTES);

        return $value >= 0 ? $value : self::LEAD_MINUTES;
    }

    private function horizonDays(): int
    {
        $value = (int) Setting::get('booking.horizon_days', (string) self::HORIZON_DAYS);

        return $value > 0 ? $value : self::HORIZON_DAYS;
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
    private function slotsForDay(CarbonImmutable $day, CarbonInterface $start, CarbonInterface $end, int $duration, ?int $step = null): Collection
    {
        $step ??= $duration;
        $tz = self::CLINIC_TIMEZONE;
        $date = $day->setTimezone($tz)->toDateString();
        $cursor = CarbonImmutable::parse("{$date} {$start->format('H:i')}", $tz);
        $dayEnd = CarbonImmutable::parse("{$date} {$end->format('H:i')}", $tz);

        $slots = collect();
        $lastPushed = null;
        while ($cursor->addMinutes($duration)->lessThanOrEqualTo($dayEnd)) {
            $slots->push(new Slot($cursor, $cursor->addMinutes($duration)));
            $lastPushed = $cursor;
            $cursor = $cursor->addMinutes($step);
        }

        // When step < duration there may be a tail gap: the last step-aligned position
        // does not fit, but dayEnd - duration does. Fill it in if it is strictly after
        // the last pushed start (avoids duplicates when step == duration).
        $tailStart = $dayEnd->subMinutes($duration);
        if ($lastPushed !== null && $tailStart->greaterThan($lastPushed)) {
            $slots->push(new Slot($tailStart, $dayEnd));
        }

        return $slots;
    }
}
