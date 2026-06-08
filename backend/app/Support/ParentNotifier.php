<?php

namespace App\Support;

use App\Mail\AppointmentCancelledParentMail;
use App\Models\Tenant\Appointment;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the "your appointment was cancelled" confirmation to the parent.
 * No-op when parent_email is absent (manual staff bookings may have none).
 * Mirrors CabinetNotifier; rescue()-wrapped so a mail failure never fails
 * the user-facing cancellation.
 */
class ParentNotifier
{
    public static function notifyCancelled(Appointment $appointment): void
    {
        $email = $appointment->parent_email;
        if (! $email) {
            return;
        }

        rescue(fn () => Mail::to($email)->queue(
            new AppointmentCancelledParentMail($appointment, config('app.name'))
        ));
    }
}
