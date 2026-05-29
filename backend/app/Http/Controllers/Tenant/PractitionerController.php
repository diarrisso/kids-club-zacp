<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StorePractitionerRequest;
use App\Models\Tenant\Practitioner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PractitionerController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Practitioners/Index', [
            'practitioners' => Practitioner::orderBy('last_name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Practitioners/Form', ['practitioner' => null]);
    }

    public function store(StorePractitionerRequest $request): RedirectResponse
    {
        Practitioner::create($request->validated());

        return redirect()->route('tenant.practitioners.index')
            ->with('success', 'Behandler wurde angelegt.');
    }

    public function edit(Practitioner $practitioner)
    {
        return Inertia::render('Tenant/Practitioners/Form', ['practitioner' => $practitioner]);
    }

    public function update(StorePractitionerRequest $request, Practitioner $practitioner): RedirectResponse
    {
        $practitioner->update($request->validated());

        return redirect()->route('tenant.practitioners.index')
            ->with('success', 'Behandler wurde aktualisiert.');
    }

    public function destroy(Practitioner $practitioner): RedirectResponse
    {
        $practitioner->delete();

        return redirect()->route('tenant.practitioners.index')
            ->with('success', 'Behandler wurde gelöscht.');
    }
}
