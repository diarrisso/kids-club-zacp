<?php

use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Public\CancellationPageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

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

/*
 * Public cancellation page — the target of the link in appointment emails.
 * Path-based tenant (/storno/{tenant}/...). 'web' supplies session + CSRF for
 * the POST form; this is a separate group from the central Route::domain routes.
 */
Route::middleware(['web', InitializeTenancyByPath::class, 'throttle:storno'])
    ->prefix('storno/{tenant}')
    ->group(function () {
        Route::get('/{token}', [CancellationPageController::class, 'show'])->name('storno.show');
        Route::post('/{token}', [CancellationPageController::class, 'cancel'])->name('storno.cancel');
    });
