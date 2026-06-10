<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Tenant/Security', [
            // `two_factor_confirmed_at` is the source of truth for "enrolled".
            'twoFactorEnabled' => ! is_null($user->two_factor_confirmed_at),
        ]);
    }
}
