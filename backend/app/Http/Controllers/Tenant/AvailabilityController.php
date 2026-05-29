<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAvailabilityRequest;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use Illuminate\Http\RedirectResponse;
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

    public function store(StoreAvailabilityRequest $request): RedirectResponse
    {
        Availability::create($request->validated());

        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeit angelegt.');
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
