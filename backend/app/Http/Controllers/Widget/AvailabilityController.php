<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function days(Request $request, AvailabilityCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        // Defence-in-depth against a calculator-DoS: an unbounded from/to span
        // (e.g. from=2020&to=2099) would loop tens of thousands of days per
        // practitioner. Reject oversized windows early (60-day horizon + month-edge margin).
        abort_if(
            CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 62,
            422,
            'Date range too large.'
        );

        $service = Service::findOrFail($data['service_id']);

        $from = CarbonImmutable::parse($data['from'], AvailabilityCalculator::CLINIC_TIMEZONE)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], AvailabilityCalculator::CLINIC_TIMEZONE)->endOfDay();

        $dates = $calculator->availableDates($service, $from, $to);

        return response()->json($dates->values());
    }
}
