<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        return Inertia::render('Central/Dashboard', [
            'tenants' => Tenant::with('plan')->latest()->get(),
        ]);
    }
}
