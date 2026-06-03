<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreQrSettingRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class QrCodeSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/QrCode', [
            'bookingUrl' => Setting::get('booking_url'),
        ]);
    }

    public function update(StoreQrSettingRequest $request): RedirectResponse
    {
        Setting::put('booking_url', $request->validated('booking_url'));

        return back()->with('success', 'QR-Code aktualisiert.');
    }
}
