<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\ConfigController;
use App\Http\Requests\Tenant\StoreAppearanceRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function index(): Response
    {
        $stored = json_decode(Setting::get('widget_theme') ?? '', true);
        $logoPath = Setting::get('widget_logo_path');

        return Inertia::render('Tenant/Appearance', [
            'theme' => array_merge(ConfigController::DEFAULT_THEME, is_array($stored) ? array_intersect_key($stored, ConfigController::DEFAULT_THEME) : []),
            'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            'datenschutzUrl' => Setting::get('datenschutz_url'),
            'impressumUrl' => Setting::get('impressum_url'),
            'fontOptions' => StoreAppearanceRequest::FONTS,
        ]);
    }

    public function update(StoreAppearanceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Setting::put('widget_theme', json_encode([
            'colorPrimary' => $data['colorPrimary'],
            'colorPrimaryTo' => $data['colorPrimaryTo'],
            'colorAccent' => $data['colorAccent'],
            'colorBackground' => $data['colorBackground'],
            'colorText' => $data['colorText'],
            'fontHeading' => $data['fontHeading'],
            'fontBody' => $data['fontBody'],
            'radius' => $data['radius'].'px',
        ]));

        $oldLogo = Setting::get('widget_logo_path');

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('widget', 'public');
            Setting::put('widget_logo_path', $path);
            if ($oldLogo && $oldLogo !== $path) {
                Storage::disk('public')->delete($oldLogo);
            }
        } elseif ($request->boolean('remove_logo') && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
            Setting::put('widget_logo_path', null);
        }

        Setting::put('datenschutz_url', $data['datenschutz_url'] ?? null);
        Setting::put('impressum_url', $data['impressum_url'] ?? null);

        return back()->with('success', 'Erscheinungsbild gespeichert.');
    }
}
