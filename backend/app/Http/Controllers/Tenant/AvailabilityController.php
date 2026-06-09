<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\BulkStoreAvailabilityRequest;
use App\Http\Requests\Tenant\StoreAvailabilityRequest;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AvailabilityController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Availabilities/Index', [
            'availabilities' => Availability::with('practitioner')
                ->orderBy('practitioner_id')
                ->orderBy('day_of_week')
                ->get(),
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Availabilities/Form', [
            'availability' => null,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function store(BulkStoreAvailabilityRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $base = [
                'practitioner_id' => $data['practitioner_id'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'slot_interval_minutes' => $data['slot_interval_minutes'] ?? null,
            ];

            if (isset($data['days_hours'])) {
                foreach ($data['days_hours'] as $dayOfWeek => $hours) {
                    Availability::create(array_merge($base, [
                        'day_of_week' => (int) $dayOfWeek,
                        'start_time' => $hours['start'],
                        'end_time' => $hours['end'],
                    ]));
                }
            } else {
                foreach ($data['days'] as $dayOfWeek) {
                    Availability::create(array_merge($base, [
                        'day_of_week' => (int) $dayOfWeek,
                        'start_time' => $data['start_time'],
                        'end_time' => $data['end_time'],
                    ]));
                }
            }
        });

        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeiten angelegt.');
    }

    public function edit(Availability $availability)
    {
        return Inertia::render('Tenant/Availabilities/Form', [
            'availability' => $availability,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function update(StoreAvailabilityRequest $request, Availability $availability): RedirectResponse
    {
        $availability->update($request->validated());

        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeit aktualisiert.');
    }

    public function destroy(Availability $availability): RedirectResponse
    {
        $availability->delete();

        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeit gelöscht.');
    }
}
