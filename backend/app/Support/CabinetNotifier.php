<?php

namespace App\Support;

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Who, at the current tenant cabinet, gets operational notifications — and the
 * one place that queues the "an appointment was cancelled" alert. The central
 * User model is pinned to the central connection (CentralConnection trait), so
 * this query is safe to run inside a tenant context.
 */
class CabinetNotifier
{
    /** @return list<string> emails of the current tenant's owners */
    public static function recipients(): array
    {
        return User::query()
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('role', 'tenant_owner')
            ->pluck('email')
            ->all();
    }

    /** Queue the cancellation alert to every cabinet recipient (no-op if none). */
    public static function notifyCancelled(Appointment $appointment): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(
            new AppointmentCancelledMail($appointment, tenant()->name)
        );
    }
}
