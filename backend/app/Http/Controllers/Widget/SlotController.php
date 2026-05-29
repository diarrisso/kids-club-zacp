<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlotController extends Controller
{
    public function index(Request $request, AvailabilityCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'practitioner_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $practitioner = Practitioner::findOrFail($data['practitioner_id']);
        $service = Service::findOrFail($data['service_id']);

        $slots = $calculator->forPractitionerService(
            $practitioner,
            $service,
            CarbonImmutable::parse($data['from'])->startOfDay(),
            CarbonImmutable::parse($data['to'])->endOfDay(),
        );

        return response()->json($slots->map->toArray()->values());
    }
}
