<?php

use App\Http\Controllers\Widget\ServiceController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

Route::middleware([InitializeTenancyByPath::class])
    ->prefix('v1/widget/{tenant}')
    ->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
    });
