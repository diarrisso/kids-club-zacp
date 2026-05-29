<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAvailabilityExceptionRequest;
use App\Models\Tenant\AvailabilityException;
use App\Models\Tenant\Practitioner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AvailabilityExceptionController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Exceptions/Index', [
            'exceptions' => AvailabilityException::with('practitioner')
                ->orderByDesc('starts_at')
                ->get(),
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Exceptions/Form', [
            'exception' => null,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function store(StoreAvailabilityExceptionRequest $request): RedirectResponse
    {
        AvailabilityException::create($request->validated());

        return redirect()->route('tenant.exceptions.index')->with('success', 'Abwesenheit angelegt.');
    }

    public function edit(AvailabilityException $exception)
    {
        return Inertia::render('Tenant/Exceptions/Form', [
            'exception' => $exception,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function update(StoreAvailabilityExceptionRequest $request, AvailabilityException $exception): RedirectResponse
    {
        $exception->update($request->validated());

        return redirect()->route('tenant.exceptions.index')->with('success', 'Abwesenheit aktualisiert.');
    }

    public function destroy(AvailabilityException $exception): RedirectResponse
    {
        $exception->delete();

        return redirect()->route('tenant.exceptions.index')->with('success', 'Abwesenheit gelöscht.');
    }
}
