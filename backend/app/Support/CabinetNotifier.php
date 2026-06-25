<?php

namespace App\Support;

use App\Mail\AppointmentBookedMail;
use App\Mail\AppointmentCancelledMail;
use App\Mail\WaitlistEntryMail;
use App\Models\Tenant\Appointment;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the "an appointment was cancelled" alert to the cabinet's configured
 * inbox (PRACTICE_NOTIFICATION_EMAIL). Single-tenant: no roles, no per-user
 * recipients.
 */
class CabinetNotifier
{
    /** @return list<string> the configured cabinet recipient(s), or [] if unset */
    public static function recipients(): array
    {
        $email = config('mail.practice_notification_address');

        return $email ? [$email] : [];
    }

    /** Queue the "new online booking" alert to the cabinet (no-op if unconfigured). */
    public static function notifyBooked(Appointment $appointment): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        rescue(fn () => Mail::to($recipients)->queue(
            new AppointmentBookedMail($appointment, config('app.name'))
        ));
    }

    /** Queue the cancellation alert to the cabinet (no-op if unconfigured). */
    public static function notifyCancelled(Appointment $appointment): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        // The appointment is already cancelled by the caller; a queue-push
        // failure (e.g. Redis down) must not fail the cancellation, so rescue().
        rescue(fn () => Mail::to($recipients)->queue(
            new AppointmentCancelledMail($appointment, config('app.name'))
        ));
    }

    /** Queue the waitlist-entry alert to the cabinet (no-op if unconfigured). */
    public static function notifyWaitlist(WaitlistEntry $entry): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        rescue(fn () => Mail::to($recipients)->queue(
            new WaitlistEntryMail($entry, config('app.name'))
        ));
    }
}
