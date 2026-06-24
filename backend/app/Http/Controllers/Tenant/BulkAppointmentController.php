<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Practitioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BulkAppointmentController extends Controller
{
    public function index(): Response
    {
        $practitioners = Practitioner::orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'title', 'first_name', 'last_name', 'color'])
            ->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color]);
        return Inertia::render('Tenant/BulkAppointments/Index', [
            'practitioners' => $practitioners,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'practitioner_id' => 'required|exists:practitioners,id',
            'date_from'       => 'required|date',
            'date_to'         => 'required|date|after_or_equal:date_from',
            'weekdays'        => 'required|array|min:1',
            'weekdays.*'      => 'integer|between:0,6',
            'duration_min'    => 'required|integer|in:15,20,30,45,60',
            'slot_count'      => 'required|integer|min:0',
        ]);
        $practitioner = Practitioner::findOrFail($validated['practitioner_id']);
        $count = $validated['slot_count'];
        return redirect()->route('tenant.bulk-appointments.index')
            ->with('success', "{$count} Termine für {$practitioner->name} eingeplant.");
    }
}
