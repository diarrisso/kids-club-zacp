<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreAppointmentRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Models\PracticeSettings;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use App\Support\CabinetNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function store(StoreAppointmentRequest $request, AvailabilityCalculator $calculator): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true]);
        }

        $data = $request->validated();
        $service = Service::findOrFail($data['service_id']);
        $practitioner = Practitioner::findOrFail($data['practitioner_id']);
        $startsAt = CarbonImmutable::parse($data['starts_at'], AvailabilityCalculator::CLINIC_TIMEZONE);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        // C2: the slot must be structurally bookable (open hours, grid, lead/horizon,
        // no exception, practitioner active + offers the service). 422 if not.
        abort_unless($calculator->isBookable($practitioner, $service, $startsAt), 422, 'Dieser Termin ist leider nicht mehr verfügbar.');

        $appointment = DB::transaction(function () use ($data, $practitioner, $service, $startsAt, $endsAt, $calculator) {
            // C3 (anti calendar-squatting): cap how many active future appointments a
            // single parent identity may hold. Done INSIDE the transaction under a
            // per-identity advisory lock so two concurrent bookings for the same parent
            // — even on different practitioners (whose row locks don't overlap) — can't
            // both read an under-cap count and both insert. The honeypot short-circuit
            // above still runs first, so a honeypot bot never reaches this.
            $this->lockIdentity($data['parent_email'], $data['parent_phone'] ?? null);

            // Thrown as a field validation error (not a bare abort(422)) so the widget,
            // which reads `errors` from a 422, surfaces the message on the email field
            // instead of silently returning the parent to an empty form.
            if ($this->activeAppointmentsForIdentity($data['parent_email'], $data['parent_phone'] ?? null)
                >= (int) config('booking.max_active_per_identity')) {
                throw ValidationException::withMessages([
                    'parent_email' => 'Zu viele aktive Termine für diese Kontaktdaten. Bitte kontaktieren Sie die Praxis.',
                ]);
            }

            // C1: serialize concurrent bookings for THIS practitioner on a real row lock.
            // A bare lockForUpdate()->exists() locks nothing when the slot is free (TOCTOU),
            // so we lock the practitioner row to force concurrent requests to queue here.
            // Reuse the LOCKED instance below so isBookable()'s in-memory attribute reads
            // (is_active) reflect the row as of the lock, not the pre-transaction load.
            $practitioner = Practitioner::query()->whereKey($practitioner->getKey())->lockForUpdate()->first();
            abort_unless($practitioner, 409, 'Slot no longer bookable.');

            // C1b: re-check full bookability UNDER the lock. isBookable() also covers
            // AvailabilityExceptions (absences) — the pre-transaction check above races
            // with staff creating an absence, so re-verify here to avoid booking into
            // an absence opened between the initial check and acquiring the lock.
            abort_unless($calculator->isBookable($practitioner, $service, $startsAt), 409, 'Slot no longer bookable.');

            $conflict = Appointment::query()
                ->where('practitioner_id', $data['practitioner_id'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();

            abort_if($conflict, 409, 'Slot already taken.');

            return Appointment::create([
                'practitioner_id' => $data['practitioner_id'],
                'service_id' => $data['service_id'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'confirmed',
                'patient_first_name' => $data['patient_first_name'],
                'patient_last_name' => $data['patient_last_name'],
                'patient_birthdate' => $data['patient_birthdate'],
                'parent_first_name' => $data['parent_first_name'],
                'parent_last_name' => $data['parent_last_name'],
                'parent_email' => $data['parent_email'],
                'parent_phone' => $data['parent_phone'] ?? null,
                'parent_consent_at' => now(),
                'notes_parent' => $data['notes_parent'] ?? null,
                'cancellation_token' => (string) Str::uuid(),
                'room' => $data['room'] ?? null,
            ]);
        });

        // Notify the parent. Two guards stack here:
        //  - per-recipient throttle (3/hour/email): caps email-bombing of a
        //    victim address; the booking itself is untouched (still 201 + row
        //    committed) — only the mail is skipped past the cap.
        //  - rescue() INSIDE the callback: a queue-push failure (e.g. Redis down)
        //    must never 500 an already-committed booking.
        $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);

        if (PracticeSettings::current()->booking_confirmation_enabled) {
            $emailKey = 'confirm-mail:'.sha1(mb_strtolower(trim($appointment->parent_email)));
            RateLimiter::attempt(
                $emailKey,
                maxAttempts: 3,
                callback: fn () => rescue(fn () => Mail::to($appointment->parent_email)->queue(
                    new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
                )),
                decaySeconds: 3600,
            );
        }

        if (PracticeSettings::current()->notify_on_booking) {
            CabinetNotifier::notifyBooked($appointment);
        }

        return response()->json([
            'reference' => $appointment->publicReference(),
            'cancellation_token' => $appointment->cancellation_token,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ], 201);
    }

    /**
     * Serialize concurrent bookings by the same parent identity via a Postgres
     * transaction-scoped advisory lock (auto-released on commit/rollback), so the
     * cap count + insert below are atomic against a same-identity race across any
     * practitioner. hashtext() maps the identity string to the lock's integer key.
     * No-op on other drivers (dev), where the in-transaction count is best-effort.
     */
    private function lockIdentity(string $email, ?string $phone): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $identity = mb_strtolower(trim($email)).'|'.trim($phone ?? '');
        DB::select('select pg_advisory_xact_lock(hashtext(?))', [$identity]);
    }

    /**
     * Count the active (pending/confirmed) FUTURE appointments already held by this
     * parent identity — matched on a case-insensitive email OR an exact phone.
     * Cancelled/past appointments never count. lower() keeps it driver-agnostic
     * (no regexp_replace, which SQLite lacks); the compared values are bound.
     */
    private function activeAppointmentsForIdentity(string $email, ?string $phone): int
    {
        $emailKey = mb_strtolower(trim($email));
        $phone = $phone !== null ? trim($phone) : '';

        return Appointment::query()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', now())
            ->where(function ($q) use ($emailKey, $phone) {
                $q->whereRaw('lower(parent_email) = ?', [$emailKey]);
                if ($phone !== '') {
                    $q->orWhere('parent_phone', $phone);
                }
            })
            ->count();
    }
}
