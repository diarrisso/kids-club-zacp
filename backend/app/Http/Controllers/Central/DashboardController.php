<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // The central dashboard lists every tenant (cross-customer registry).
        // Only super admins may see it — never a tenant owner.
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return Inertia::render('Central/Dashboard', [
            'tenants' => Tenant::with('plan')->latest()->get(),
        ]);
    }
}
