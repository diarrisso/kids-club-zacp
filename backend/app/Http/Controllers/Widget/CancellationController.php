<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Support\CabinetNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CancellationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $a = Appointment::where('cancellation_token', $token)->firstOrFail();

        return response()->json([
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'status' => $a->status,
            'service' => $a->service->name,
        ]);
    }

    public function cancel(string $token): JsonResponse
    {
        // Lock + flip inside the transaction; return the appointment only when
        // THIS request performed the cancellation (null if already cancelled).
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

        // Notify the cabinet only AFTER the commit, so a rolled-back cancellation
        // can never produce a false alert. (notifyCancelled rescue()-wraps the push.)
        if ($cancelled) {
            CabinetNotifier::notifyCancelled($cancelled);
        }

        return response()->json(['status' => 'cancelled']);
    }
}
