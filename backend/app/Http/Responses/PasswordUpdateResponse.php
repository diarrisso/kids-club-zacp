<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;

/**
 * After a successful password change, send the user to the dashboard with a
 * success flash (read by TenantLayout) instead of bouncing back to the security
 * page. The change is initiated only from the security page's "Passwort ändern"
 * form, so dashboard is the natural landing.
 */
class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()
            ->route('tenant.dashboard')
            ->with('success', 'Passwort erfolgreich geändert.');
    }
}
