<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Support\CabinetNotifier;
use App\Support\ParentNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class CancellationPageController extends Controller
{
    public function show(string $token): View
    {
        $appointment = Appointment::where('cancellation_token', $token)
            ->with(['service', 'practitioner'])
            ->firstOrFail();

        if ($appointment->status === 'cancelled') {
            return view('storno.done', ['cabinetName' => config('app.name')]);
        }

        return view('storno.show', [
            'appointment' => $appointment,
            'cabinetName' => config('app.name'),
            'token' => $token,
        ]);
    }

    public function cancel(string $token): View
    {
        $cancelled = DB::transaction(function () use ($token) {
            $appointment = Appointment::where('cancellation_token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            if ($appointment->status === 'cancelled') {
                return null;
            }

            $appointment->update(['status' => 'cancelled']);

            return $appointment;
        });

        // Notify the cabinet and parent only AFTER the commit (see CancellationController).
        if ($cancelled) {
            CabinetNotifier::notifyCancelled($cancelled);
            ParentNotifier::notifyCancelled($cancelled);
        }

        return view('storno.done', ['cabinetName' => config('app.name')]);
    }
}
