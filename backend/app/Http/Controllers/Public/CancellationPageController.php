<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Support\CabinetNotifier;
use Illuminate\Contracts\View\View;

class CancellationPageController extends Controller
{
    public function show(string $token): View
    {
        $appointment = Appointment::where('cancellation_token', $token)
            ->with(['service', 'practitioner'])
            ->firstOrFail();

        if ($appointment->status === 'cancelled') {
            return view('storno.done', ['cabinetName' => tenant()->name]);
        }

        return view('storno.show', [
            'appointment' => $appointment,
            'cabinetName' => tenant()->name,
            'token' => $token,
        ]);
    }

    public function cancel(string $token): View
    {
        $appointment = Appointment::where('cancellation_token', $token)->firstOrFail();

        // Idempotent: only cancel + notify once. A second POST is a no-op.
        if ($appointment->status !== 'cancelled') {
            $appointment->update(['status' => 'cancelled']);
            CabinetNotifier::notifyCancelled($appointment);
        }

        return view('storno.done', ['cabinetName' => tenant()->name]);
    }
}
