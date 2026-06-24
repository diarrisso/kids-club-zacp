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

    public function batchUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'practitioner_id'          => 'required|exists:practitioners,id',
            'schedule'                 => 'required|array|size:7',
            'schedule.*.day_of_week'   => 'required|integer|between:1,7',
            'schedule.*.open'          => 'required|boolean',
            'schedule.*.start_time'    => 'nullable|date_format:H:i',
            'schedule.*.end_time'      => 'nullable|date_format:H:i',
        ]);

        DB::transaction(function () use ($data) {
            Availability::where('practitioner_id', $data['practitioner_id'])
                ->whereNull('valid_from')
                ->whereNull('valid_to')
                ->delete();

            foreach ($data['schedule'] as $day) {
                if (! $day['open']) continue;
                Availability::create([
                    'practitioner_id' => $data['practitioner_id'],
                    'day_of_week'     => $day['day_of_week'],
                    'start_time'      => $day['start_time'],
                    'end_time'        => $day['end_time'],
                    'valid_from'      => null,
                    'valid_to'        => null,
                ]);
            }
        });

        $practitioner = Practitioner::find($data['practitioner_id']);
        return redirect()->route('tenant.availabilities.index')
            ->with('success', "Sprechzeiten für {$practitioner->name} gespeichert.");
    }

    public function destroy(Availability $availability): RedirectResponse
    {
        $availability->delete();

        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeit gelöscht.');
    }
}
