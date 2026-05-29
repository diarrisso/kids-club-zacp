<?php

use App\Http\Controllers\Central\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Central (SaaS) routes — served only on the central domains.
 * Tenant domains are handled in routes/tenant.php.
 */
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', fn () => Inertia::render('Central/Landing'))->name('central.landing');

        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('auth')
            ->name('central.dashboard');
    });
}
