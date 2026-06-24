<?php

namespace App\Support;

use App\Mail\WaitlistSlotAvailableMail;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Promotes the oldest pending waitlist entry to "contacted" and, when an
 * email address is present, queues a "slot available" notification.
 * The read+flip runs inside a transaction with a row-level lock so two
 * concurrent cancellations cannot both promote the same entry.
 * The mail push happens after the commit — queuing inside a transaction
 * risks firing before the row is visible to the mail worker.
 */
class WaitlistNotifier
{
    public static function notifySlotAvailable(): void
    {
        $entry = DB::transaction(function () {
            $e = WaitlistEntry::where('status', WaitlistStatus::Pending->value)
                ->oldest()
                ->lockForUpdate()
                ->first();

            if (! $e) {
                return null;
            }

            $e->status = WaitlistStatus::Contacted;
            $e->save();

            return $e;
        });

        if (! $entry || ! filled($entry->parent_email)) {
            return;
        }

        rescue(fn () => Mail::to($entry->parent_email)->queue(
            new WaitlistSlotAvailableMail($entry, config('app.name'))
        ));
    }
}
