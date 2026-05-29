<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use Illuminate\Http\JsonResponse;

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
        $a = Appointment::where('cancellation_token', $token)->firstOrFail();
        $a->update(['status' => 'cancelled']);

        return response()->json(['status' => 'cancelled']);
    }
}
