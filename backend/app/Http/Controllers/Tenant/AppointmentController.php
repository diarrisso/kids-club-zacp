<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreManualAppointmentRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AppointmentScheduler;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    private const TZ = AvailabilityCalculator::CLINIC_TIMEZONE;

    public function index(): Response
    {
        return Inertia::render('Tenant/Appointments/Calendar', [
            'practitioners' => Practitioner::query()->orderBy('last_name')->get()
                ->map(fn (Practitioner $p) => ['id' => $p->id, 'name' => $p->fullName(), 'color' => $p->color])
                ->all(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name, 'duration_minutes' => $s->duration_minutes])
                ->all(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $start = CarbonImmutable::parse($request->query('start'), self::TZ);
        $end = CarbonImmutable::parse($request->query('end'), self::TZ);
        $practitionerIds = array_filter((array) $request->query('practitioner_ids', []));

        $appointments = Appointment::query()
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($practitionerIds, fn ($q) => $q->whereIn('practitioner_id', $practitionerIds))
            ->with(['service', 'practitioner'])
            ->get()
            ->map(fn (Appointment $a) => $this->toDto($a))
            ->all();

        return response()->json($appointments);
    }

    public function store(StoreManualAppointmentRequest $request, AppointmentScheduler $scheduler): JsonResponse
    {
        $data = $request->validated();
        $service = Service::findOrFail($data['service_id']);
        $startsAt = CarbonImmutable::parse($data['starts_at'], self::TZ);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        // Cabinet override: only the overlap rule applies (inside the scheduler).
        $appointment = $scheduler->create([
            'practitioner_id' => $data['practitioner_id'],
            'service_id' => $data['service_id'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'patient_first_name' => $data['patient_first_name'],
            'patient_last_name' => $data['patient_last_name'],
            'patient_birthdate' => $data['patient_birthdate'],
            'parent_first_name' => $data['parent_first_name'],
            'parent_last_name' => $data['parent_last_name'],
            'parent_phone' => $data['parent_phone'],
            'parent_email' => $data['parent_email'] ?? null,
            // Manual bookings carry no explicit electronic consent record.
            'parent_consent_at' => null,
        ]);

        // notes_internal is intentionally NOT $fillable -> set by direct assignment.
        if (filled($data['notes_internal'] ?? null)) {
            $appointment->notes_internal = $data['notes_internal'];
            $appointment->save();
        }

        // Confirmation only when we actually have an address. Post-commit + rescue
        // so a queue-push failure can't 500 the already-created appointment.
        // Mirrors the widget controller (single-tenant: no tenant() calls).
        if (filled($appointment->parent_email)) {
            $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);
            rescue(fn () => Mail::to($appointment->parent_email)->queue(
                new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
            ));
        }

        return response()->json($this->toDto($appointment->load(['service', 'practitioner'])), 201);
    }

    /** Lightweight appointment shape consumed by the TS calendar mapper. */
    private function toDto(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'starts_at' => $this->toClinicIso($a->starts_at),
            'ends_at' => $this->toClinicIso($a->ends_at),
            'status' => $a->status,
            'patient_first_name' => $a->patient_first_name,
            'patient_last_name' => $a->patient_last_name,
            'patient_birthdate' => $a->patient_birthdate?->toDateString(),
            'parent_first_name' => $a->parent_first_name,
            'parent_last_name' => $a->parent_last_name,
            'parent_email' => $a->parent_email,
            'parent_phone' => $a->parent_phone,
            'notes_internal' => $a->notes_internal,
            'practitioner' => ['id' => $a->practitioner->id, 'name' => $a->practitioner->fullName(), 'color' => $a->practitioner->color],
            'service' => ['id' => $a->service->id, 'name' => $a->service->name, 'duration_minutes' => $a->service->duration_minutes],
        ];
    }

    /**
     * Serialize a stored appointment timestamp as a clinic-timezone ISO string.
     *
     * The `appointments.starts_at/ends_at` columns are plain `timestamp`s holding
     * wall-clock clinic time (Europe/Berlin), which Eloquent re-reads as UTC. The
     * widget's public slot feed (App\Services\Tenant\Slot) emits Berlin-offset ISO
     * because its slots are computed in Berlin and never round-tripped through the
     * DB. To match that convention — and so FullCalendar (timeZone: Europe/Berlin)
     * renders the right hour — re-label the stored wall clock as Berlin instead of
     * converting it.
     */
    private function toClinicIso(\Carbon\CarbonInterface $dt): string
    {
        return CarbonImmutable::parse($dt->format('Y-m-d H:i:s'), self::TZ)->toIso8601String();
    }
}
