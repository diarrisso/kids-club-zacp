<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateSettingsRequest;
use App\Models\PracticeSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Settings/Index', [
            'settings' => PracticeSettings::current(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        PracticeSettings::current()->update($request->validated());

        return back()->with('success', 'Einstellungen gespeichert.');
    }
}
