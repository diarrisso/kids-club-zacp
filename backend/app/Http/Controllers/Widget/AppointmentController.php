<?php
namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreAppointmentRequest;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true]);
        }

        $data = $request->validated();
        $service = Service::findOrFail($data['service_id']);
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        $appointment = DB::transaction(function () use ($data, $startsAt, $endsAt) {
            $conflict = Appointment::query()
                ->where('practitioner_id', $data['practitioner_id'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->whereIn('status', ['pending', 'confirmed'])
                ->lockForUpdate()
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

        return response()->json([
            'cancellation_token' => $appointment->cancellation_token,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ], 201);
    }
}
