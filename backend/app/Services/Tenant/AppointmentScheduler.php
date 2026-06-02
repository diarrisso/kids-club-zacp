<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cabinet-side scheduling. Unlike the public widget (which enforces
 * AvailabilityCalculator::isBookable — open hours, grid, lead, horizon), the
 * cabinet has authority: the ONLY hard rule is "no overlap for the same
 * practitioner". Open-hours/grid/lead are intentionally NOT enforced here.
 */
class AppointmentScheduler
{
    /** @param array $data fillable appointment fields (practitioner_id, service_id, starts_at, ends_at, patient_*, parent_*) */
    public function create(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            $this->assertNoOverlap($data['practitioner_id'], $data['starts_at'], $data['ends_at']);

            return Appointment::create($data + [
                'status' => 'confirmed',
                'cancellation_token' => (string) Str::uuid(),
            ]);
        });
    }

    /** @param array $changes subset of fillable fields (e.g. starts_at/ends_at for drag&drop) */
    public function reschedule(Appointment $appointment, array $changes): Appointment
    {
        return DB::transaction(function () use ($appointment, $changes) {
            $this->assertNoOverlap(
                $changes['practitioner_id'] ?? $appointment->practitioner_id,
                $changes['starts_at'] ?? $appointment->starts_at,
                $changes['ends_at'] ?? $appointment->ends_at,
                $appointment->id,
            );
            $appointment->update($changes);

            return $appointment->refresh();
        });
    }

    private function assertNoOverlap(int $practitionerId, $startsAt, $endsAt, ?string $exceptId = null): void
    {
        // Lock the practitioner ROW (never an aggregate — PostgreSQL rule), as in
        // the Phase 2 booking flow, to serialise concurrent writes.
        Practitioner::query()->whereKey($practitionerId)->lockForUpdate()->first();

        $conflict = Appointment::query()
            ->where('practitioner_id', $practitionerId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->whereIn('status', ['pending', 'confirmed'])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        abort_if($conflict, 409, 'Überschneidung mit einem bestehenden Termin.');
    }
}
