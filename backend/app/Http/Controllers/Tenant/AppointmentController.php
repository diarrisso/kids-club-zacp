<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** Lightweight appointment shape consumed by the TS calendar mapper. */
    private function toDto(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'starts_at' => $a->starts_at->setTimezone(self::TZ)->toIso8601String(),
            'ends_at' => $a->ends_at->setTimezone(self::TZ)->toIso8601String(),
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
}
