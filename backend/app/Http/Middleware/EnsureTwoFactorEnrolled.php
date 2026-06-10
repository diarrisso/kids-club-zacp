<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory two-factor: an authenticated user without a confirmed TOTP factor is
 * redirected to the security page and cannot reach any other staff route. Only the
 * enrolment, password-confirmation, password-update, and logout routes are allowed
 * through so the user can actually escape the block.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isAllowed = $request->routeIs(
            'tenant.security.*',     // the security/enrolment page
            'two-factor.*',          // Fortify enable/confirm/disable/qr/recovery/challenge
            'password.confirm',      // Fortify confirm-password gate
            'user-password.update',  // Fortify password change
            'logout',                // escape hatch
        );

        if ($user && is_null($user->two_factor_confirmed_at) && ! $isAllowed) {
            return redirect()->route('tenant.security.index')
                ->with('warning', 'Zwei-Faktor-Authentifizierung ist erforderlich.');
        }

        return $next($request);
    }
}
