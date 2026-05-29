<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\AvailabilityController;
use App\Http\Controllers\Tenant\AvailabilityExceptionController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\PractitionerController;
use App\Http\Controllers\Tenant\ServiceController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Served on tenant domains (e.g. kidsclub.masinga-booking.test). The 'web'
| middleware is applied by TenancyServiceProvider::mapRoutes(); here we only
| add tenant identification + central-domain protection.
|
*/

Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', fn () => redirect()->route('tenant.dashboard'));

    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

        Route::resource('behandler', PractitionerController::class)
            ->names('tenant.practitioners')
            ->parameters(['behandler' => 'practitioner']);

        Route::resource('leistungen', ServiceController::class)
            ->names('tenant.services')
            ->parameters(['leistungen' => 'service']);

        Route::resource('sprechzeiten', AvailabilityController::class)
            ->names('tenant.availabilities')
            ->parameters(['sprechzeiten' => 'availability']);

        Route::resource('abwesenheiten', AvailabilityExceptionController::class)
            ->names('tenant.exceptions')
            ->parameters(['abwesenheiten' => 'exception']);
    });
});
