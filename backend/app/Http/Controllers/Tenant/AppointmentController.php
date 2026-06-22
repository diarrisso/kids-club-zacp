<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ListAppointmentsRequest;
use App\Http\Requests\Tenant\StoreManualAppointmentRequest;
use App\Http\Requests\Tenant\UpdateAppointmentRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AppointmentScheduler;
use App\Services\Tenant\AvailabilityCalculator;
use App\Support\ParentNotifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    public function list(ListAppointmentsRequest $request): Response
    {
        $filters = $request->validated();
        $q = $filters['q'] ?? null;

        $appointments = Appointment::query()
            ->with(['service', 'practitioner'])
            ->when($q, function ($query) use ($q) {
                // Escape LIKE wildcards so a typed % / _ doesn't widen the search.
                // The value is a BOUND parameter — never concatenated into SQL.
                $term = '%'.addcslashes($q, '%_\\').'%';
                $query->where(function ($sub) use ($term) {
                    $sub->where('patient_first_name', 'ILIKE', $term)
                        ->orWhere('patient_last_name', 'ILIKE', $term)
                        ->orWhere('parent_first_name', 'ILIKE', $term)
                        ->orWhere('parent_last_name', 'ILIKE', $term);
                });
            })
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('starts_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('starts_at', '<=', $to))
            ->when($filters['attendance'] ?? null, fn ($query, $att) => $query->where('attendance', $att))
            ->orderByDesc('starts_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Appointment $a) => $this->toDto($a));

        return Inertia::render('Tenant/Appointments/List', [
            'appointments' => $appointments,
            'filters' => [
                'q' => $q,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'attendance' => $filters['attendance'] ?? null,
            ],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        // Validate before parsing: an invalid date would otherwise make
        // CarbonImmutable::parse() throw and return a 500.
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
            'practitioner_ids' => ['sometimes', 'array'],
            'practitioner_ids.*' => ['integer'],
        ]);

        $start = CarbonImmutable::parse($validated['start'], self::TZ);
        $end = CarbonImmutable::parse($validated['end'], self::TZ);
        $practitionerIds = array_filter((array) ($validated['practitioner_ids'] ?? []));

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
            'room' => $data['room'] ?? null,
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

    public function update(UpdateAppointmentRequest $request, AppointmentScheduler $scheduler, Appointment $appointment): JsonResponse
    {
        $data = $request->validated();

        // notes_internal is not $fillable — strip it from the scheduler payload
        // and apply it directly afterwards.
        $notesInternal = $data['notes_internal'] ?? null;
        $hasNotes = array_key_exists('notes_internal', $data);
        unset($data['notes_internal']);

        // attendance is not $fillable — strip it from the scheduler payload
        // and apply it directly afterwards (staff-only, like notes_internal).
        $hasAttendance = array_key_exists('attendance', $data);
        $attendance = $data['attendance'] ?? null;
        unset($data['attendance']);

        // Normalise dates to Berlin. If the service changed without an explicit
        // ends_at, recompute the end from the (new or existing) start + duration.
        if (isset($data['starts_at'])) {
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], self::TZ);
        }
        if (isset($data['ends_at'])) {
            $data['ends_at'] = CarbonImmutable::parse($data['ends_at'], self::TZ);
        } elseif (isset($data['service_id'])) {
            $service = Service::findOrFail($data['service_id']);
            $start = $data['starts_at'] ?? $appointment->starts_at;
            // Re-label the wall clock as Berlin (don't rely on parse() ignoring the
            // tz arg for DateTime instances), mirroring toClinicIso().
            $startWall = $start instanceof \DateTimeInterface ? $start->format('Y-m-d H:i:s') : $start;
            $data['ends_at'] = CarbonImmutable::parse($startWall, self::TZ)->addMinutes($service->duration_minutes);
        }

        $appointment = $scheduler->reschedule($appointment, $data);

        if ($hasNotes) {
            $appointment->notes_internal = $notesInternal;
            $appointment->save();
        }

        if ($hasAttendance) {
            $appointment->attendance = $attendance;
            $appointment->save();
        }

        return response()->json($this->toDto($appointment->load(['service', 'practitioner'])));
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        // Cabinet cancellation: free the slot (the feed excludes 'cancelled').
        // Notify the parent only on a real transition (not if already cancelled).
        if ($appointment->status !== 'cancelled') {
            $appointment->update(['status' => 'cancelled']);
            ParentNotifier::notifyCancelled($appointment);
        }

        return response()->json(['status' => 'cancelled']);
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
            'room' => $a->room?->value,
            'attendance' => $a->attendance?->value,
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
    private function toClinicIso(CarbonInterface $dt): string
    {
        return CarbonImmutable::parse($dt->format('Y-m-d H:i:s'), self::TZ)->toIso8601String();
    }
}
