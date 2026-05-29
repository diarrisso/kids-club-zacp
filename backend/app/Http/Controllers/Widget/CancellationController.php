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
        DB::transaction(function () use ($token) {
            $appointment = Appointment::where('cancellation_token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent + race-safe: the row lock serialises concurrent cancels,
            // so the cabinet is notified at most once.
            if ($appointment->status !== 'cancelled') {
                $appointment->update(['status' => 'cancelled']);
                CabinetNotifier::notifyCancelled($appointment);
            }
        });

        return response()->json(['status' => 'cancelled']);
    }
}
