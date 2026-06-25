<?php

use App\Http\Controllers\Public\CancellationPageController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\Tenant\AppearanceController;
use App\Http\Controllers\Tenant\AppointmentController;
use App\Http\Controllers\Tenant\AvailabilityController;
use App\Http\Controllers\Tenant\AvailabilityExceptionController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\PractitionerController;
use App\Http\Controllers\Tenant\QrCodeSettingController;
use App\Http\Controllers\Tenant\SecurityController;
use App\Http\Controllers\Tenant\ServiceController;
use App\Http\Controllers\Tenant\SettingsController;
use App\Http\Controllers\Tenant\StatisticsController;
use App\Http\Controllers\Tenant\WaitlistController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Public landing.
 */
Route::get('/', fn () => Inertia::render('Central/Landing'))->name('landing');

/*
 * Public QR code image — anonymous, rate-limited.
 */
Route::middleware('throttle:qr')
    ->get('/termin-qrcode.{format}', [QrCodeController::class, 'show'])
    ->where('format', 'png|svg')
    ->name('qr.image');

/*
 * Cabinet admin (single tenant). German URLs, route names kept stable.
 */
Route::middleware(['auth', 'two-factor.enrolled'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

    // Security / 2FA settings.
    Route::get('/sicherheit', [SecurityController::class, 'index'])
        ->name('tenant.security.index');

    Route::resource('behandler', PractitionerController::class)
        ->names('tenant.practitioners')
        ->parameters(['behandler' => 'practitioner']);

    Route::resource('leistungen', ServiceController::class)
        ->names('tenant.services')
        ->parameters(['leistungen' => 'service']);

    Route::put('/sprechzeiten/batch', [AvailabilityController::class, 'batchUpdate'])->name('tenant.availabilities.batch-update');
    Route::resource('sprechzeiten', AvailabilityController::class)
        ->names('tenant.availabilities')
        ->parameters(['sprechzeiten' => 'availability']);

    Route::resource('abwesenheiten', AvailabilityExceptionController::class)
        ->names('tenant.exceptions')
        ->parameters(['abwesenheiten' => 'exception']);

    // QR code settings.
    Route::get('/termin-qr-code', [QrCodeSettingController::class, 'index'])->name('tenant.qr.index');
    Route::post('/termin-qr-code', [QrCodeSettingController::class, 'update'])->name('tenant.qr.update');

    // Widget-Erscheinungsbild (Theme, Logo, Rechtslinks)
    Route::get('/erscheinungsbild', [AppearanceController::class, 'index'])->name('tenant.appearance.index');
    Route::post('/erscheinungsbild', [AppearanceController::class, 'update'])->name('tenant.appearance.update');

    // Phase 5 — calendrier dashboard (gestion des RDV).
    Route::get('/termine/liste', [AppointmentController::class, 'list'])->name('tenant.appointments.list');
    Route::get('/termine', [AppointmentController::class, 'index'])->name('tenant.appointments.index');
    Route::get('/termine/events', [AppointmentController::class, 'events'])->name('tenant.appointments.events');
    Route::post('/termine', [AppointmentController::class, 'store'])->name('tenant.appointments.store');
    Route::patch('/termine/{appointment}', [AppointmentController::class, 'update'])->name('tenant.appointments.update');
    Route::delete('/termine/{appointment}', [AppointmentController::class, 'destroy'])->name('tenant.appointments.destroy');

    Route::get('/statistiken', [StatisticsController::class, 'index'])
        ->name('tenant.statistics.index');

    Route::get('/statistiken/export', [StatisticsController::class, 'export'])
        ->name('tenant.statistics.export');

    Route::get('/warteliste', [WaitlistController::class, 'index'])
        ->name('tenant.waitlist.index');
    Route::patch('/warteliste/{entry}', [WaitlistController::class, 'update'])
        ->name('tenant.waitlist.update');

    // Practice settings.
    Route::get('/einstellungen', [SettingsController::class, 'index'])->name('tenant.settings.index');
    Route::patch('/einstellungen', [SettingsController::class, 'update'])->name('tenant.settings.update');
});

/*
 * Public cancellation page — the target of the link in appointment emails.
 * 'web' supplies session + CSRF for the POST form.
 */
Route::middleware(['throttle:storno'])
    ->prefix('storno')
    ->group(function () {
        Route::get('/{token}', [CancellationPageController::class, 'show'])->whereUuid('token')->name('storno.show');
        Route::post('/{token}', [CancellationPageController::class, 'cancel'])->whereUuid('token')->name('storno.cancel');
    });
