<?php

namespace App\Support;

use App\Mail\WaitlistSlotAvailableMail;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\Mail;

/**
 * Promotes the oldest pending waitlist entry to "contacted" and, when an
 * email address is present, queues a "slot available" notification.
 * The status flip happens before the mail push so a queue failure never
 * leaves the entry invisibly stuck at pending.
 */
class WaitlistNotifier
{
    public static function notifySlotAvailable(): void
    {
        $entry = WaitlistEntry::where('status', WaitlistStatus::Pending->value)
            ->oldest()
            ->first();

        if (! $entry) {
            return;
        }

        $entry->status = WaitlistStatus::Contacted;
        $entry->save();

        if (! filled($entry->parent_email)) {
            return;
        }

        rescue(fn () => Mail::to($entry->parent_email)->queue(
            new WaitlistSlotAvailableMail($entry, config('app.name'))
        ));
    }
}
