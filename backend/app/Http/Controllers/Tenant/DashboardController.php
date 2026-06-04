<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Support\Room;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const TZ = 'Europe/Berlin';

    /**
     * Build the staff dashboard payload: role context, KPI stats (today/week/next),
     * the role-scoped list of today's appointments and the room legend.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $now = CarbonImmutable::now(self::TZ);
        $dayStart = $now->startOfDay();
        $dayEnd = $now->endOfDay();
        $weekStart = $now->startOfWeek();
        $weekEnd = $now->endOfWeek();

        // A medecin linked to a fiche sees only their RDV; everyone else (and an
        // unlinked medecin) sees all — graceful degradation, never a hard block.
        $practitionerId = $user->isMedecin() ? $user->practitioner_id : null;

        $scoped = fn ($q) => $q
            ->where('status', '!=', 'cancelled')
            ->when($practitionerId, fn ($qq) => $qq->where('practitioner_id', $practitionerId));

        $todayCount = $scoped(Appointment::query())
            ->whereBetween('starts_at', [$dayStart, $dayEnd])->count();

        $weekCount = $scoped(Appointment::query())
            ->whereBetween('starts_at', [$weekStart, $weekEnd])->count();

        $todayAppointments = $scoped(Appointment::query())
            ->whereBetween('starts_at', [$dayStart, $dayEnd])
            ->with(['service', 'practitioner'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'time' => $a->starts_at->format('H:i'),
                'patient' => trim($a->patient_first_name.' '.mb_substr($a->patient_last_name, 0, 1).'.'),
                'service' => $a->service->name,
                'room' => $a->room?->value,
                'practitioner' => ['name' => $a->practitioner->fullName(), 'color' => $a->practitioner->color],
            ])->all();

        $next = $scoped(Appointment::query())
            ->where('starts_at', '>=', $now)
            ->with('service')
            ->orderBy('starts_at')
            ->first();

        return Inertia::render('Tenant/Dashboard', [
            'role' => $user->role,
            'practitioner' => $user->practitioner
                ? ['id' => $user->practitioner->id, 'name' => $user->practitioner->fullName(), 'color' => $user->practitioner->color]
                : null,
            'stats' => [
                'todayCount' => $todayCount,
                'weekCount' => $weekCount,
                'activePractitioners' => Practitioner::where('is_active', true)->count(),
                'nextAppointment' => $next ? [
                    'time' => $next->starts_at->format('H:i'),
                    'patient' => trim($next->patient_first_name.' '.mb_substr($next->patient_last_name, 0, 1).'.'),
                    'service' => $next->service->name,
                ] : null,
            ],
            'todayAppointments' => $todayAppointments,
            'rooms' => Room::options(),
        ]);
    }
}
