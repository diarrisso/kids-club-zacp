<?php

use App\Http\Controllers\Widget\AppointmentController;
use App\Http\Controllers\Widget\CancellationController;
use App\Http\Controllers\Widget\ServiceController;
use App\Http\Controllers\Widget\SlotController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/widget')->group(function () {
    Route::middleware('throttle:widget-read')->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{service}/practitioners', [ServiceController::class, 'practitioners']);
        Route::get('/slots', [SlotController::class, 'index']);
        Route::get('/appointments/{token}', [CancellationController::class, 'show']);
    });

    Route::middleware('throttle:widget-book')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::post('/appointments/{token}/cancel', [CancellationController::class, 'cancel']);
    });
});
