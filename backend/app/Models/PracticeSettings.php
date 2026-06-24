<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticeSettings extends Model
{
    protected $table = 'practice_settings';

    protected $fillable = [
        'reminder_enabled',
        'reminder_channel',
        'reminder_lead_hours',
        'reminder_message',
        'booking_confirmation_enabled',
        'notify_on_booking',
        'notify_on_cancellation',
    ];

    protected $casts = [
        'reminder_enabled'             => 'boolean',
        'reminder_lead_hours'          => 'integer',
        'booking_confirmation_enabled' => 'boolean',
        'notify_on_booking'            => 'boolean',
        'notify_on_cancellation'       => 'boolean',
    ];

    /**
     * Always returns the single practice settings row, creating it with
     * sensible defaults if it doesn't exist yet.
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'reminder_enabled'             => true,
            'reminder_channel'             => 'email',
            'reminder_lead_hours'          => 24,
            'reminder_message'             => 'Liebe Familie, wir erinnern an den Termin im Kids Club am {Datum} um {Uhrzeit}. Bis bald!',
            'booking_confirmation_enabled' => true,
            'notify_on_booking'            => true,
            'notify_on_cancellation'       => false,
        ]);
    }
}
