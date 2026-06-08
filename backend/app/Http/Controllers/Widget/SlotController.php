<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
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
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'practitioner_id' => ['nullable', 'integer', 'exists:practitioners,id'],
        ]);

        $service = Service::findOrFail($data['service_id']);

        // One practitioner if explicitly requested (back-compat), else every active
        // practitioner offering this service (the date-first, multi-doctor flow).
        // Both branches are scoped to the service relation so an unrelated practitioner
        // never returns slots for this service. active() on both branches so an inactive
        // practitioner never leaks slots onto the public widget — an inactive id yields [].
        $practitioners = ($data['practitioner_id'] ?? null) !== null
            ? $service->practitioners()->active()->whereKey($data['practitioner_id'])->get()
            : $service->practitioners()->active()->get();

        $from = CarbonImmutable::parse($data['from'], AvailabilityCalculator::CLINIC_TIMEZONE)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], AvailabilityCalculator::CLINIC_TIMEZONE)->endOfDay();

        $slots = collect();
        // mirrors AvailabilityCalculator::availableDates fan-out
        foreach ($practitioners as $practitioner) {
            foreach ($calculator->forPractitionerService($practitioner, $service, $from, $to) as $slot) {
                $slots->push($slot->toArray() + [
                    'practitioner' => [
                        'id' => $practitioner->id,
                        'first_name' => $practitioner->first_name,
                        'last_name' => $practitioner->last_name,
                        'title' => $practitioner->title,
                        'color' => $practitioner->color,
                    ],
                ]);
            }
        }

        return response()->json(
            $slots->sortBy([
                ['starts_at', 'asc'],
                ['practitioner.last_name', 'asc'],
            ])->values()->all()
        );
    }
}
