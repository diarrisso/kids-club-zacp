<?php
namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreAppointmentRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        $startsAt = CarbonImmutable::parse($data['starts_at'], \App\Services\Tenant\AvailabilityCalculator::CLINIC_TIMEZONE);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        // C2: the slot must be structurally bookable (open hours, grid, lead/horizon,
        // no exception, practitioner active + offers the service). 422 if not.
        abort_unless($calculator->isBookable($practitioner, $service, $startsAt), 422, 'Slot not bookable.');

        $appointment = DB::transaction(function () use ($data, $practitioner, $startsAt, $endsAt) {
            // C1: serialize concurrent bookings for THIS practitioner on a real row lock.
            // A bare lockForUpdate()->exists() locks nothing when the slot is free (TOCTOU),
            // so we lock the practitioner row to force concurrent requests to queue here.
            Practitioner::query()->whereKey($practitioner->getKey())->lockForUpdate()->first();

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
            ]);
        });

        // Notify the parent. Queued so it never blocks the booking response; the
        // booking is already committed here, so a queue-push failure (e.g. Redis
        // down) must not 500 an existing booking — rescue() logs and moves on.
        // The cancel link targets the public /storno page (path-based tenant).
        $cancelUrl = route('storno.show', [
            'tenant' => tenant()->getTenantKey(),
            'token' => $appointment->cancellation_token,
        ]);
        rescue(fn () => Mail::to($appointment->parent_email)->queue(
            new AppointmentConfirmationMail($appointment, tenant()->name, $cancelUrl)
        ));

        return response()->json([
            'cancellation_token' => $appointment->cancellation_token,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ], 201);
    }
}
