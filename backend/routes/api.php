<?php

use App\Http\Controllers\Widget\ServiceController;
use App\Http\Controllers\Widget\SlotController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

Route::middleware([InitializeTenancyByPath::class])
    ->prefix('v1/widget/{tenant}')
    ->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{service}/practitioners', [ServiceController::class, 'practitioners']);
        Route::get('/slots', [SlotController::class, 'index']);
    });
