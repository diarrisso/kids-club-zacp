<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreServiceRequest;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Support\CatalogCache;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ServiceController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Services/Index', [
            'services' => Service::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Services/Form', [
            'service' => null,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $practitionerIds = $data['practitioner_ids'] ?? [];
        unset($data['practitioner_ids']);

        $service = Service::create($data);
        $service->practitioners()->sync($practitionerIds);
        // Explicit flush: practitioners()->sync() changes the pivot WITHOUT firing
        // a model event, so the CatalogObserver can't see it — this is not redundant
        // with the save-triggered flush above.
        CatalogCache::flush();

        return redirect()->route('tenant.services.index')->with('success', 'Leistung angelegt.');
    }

    public function edit(Service $service)
    {
        return Inertia::render('Tenant/Services/Form', [
            'service' => $service->load('practitioners'),
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function update(StoreServiceRequest $request, Service $service): RedirectResponse
    {
        $data = $request->validated();
        $practitionerIds = $data['practitioner_ids'] ?? [];
        unset($data['practitioner_ids']);

        $service->update($data);
        $service->practitioners()->sync($practitionerIds);
        // Explicit flush: practitioners()->sync() changes the pivot WITHOUT firing
        // a model event, so the CatalogObserver can't see it — this is not redundant
        // with the save-triggered flush above.
        CatalogCache::flush();

        return redirect()->route('tenant.services.index')->with('success', 'Leistung aktualisiert.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('tenant.services.index')->with('success', 'Leistung gelöscht.');
    }
}
