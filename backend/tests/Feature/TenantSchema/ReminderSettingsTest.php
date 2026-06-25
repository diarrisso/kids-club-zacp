<?php

use App\Mail\AppointmentReminderMail;
use App\Models\PracticeSettings;
use App\Models\Tenant\Appointment;
use Illuminate\Support\Facades\Mail;

it('sends no reminder when reminders are disabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['reminder_enabled' => false]);
    Appointment::factory()->create([
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(24)->addMinutes(30),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('uses the configured lead time window', function () {
    Mail::fake();
    PracticeSettings::current()->update(['reminder_enabled' => true, 'reminder_lead_hours' => 48]);

    $due = Appointment::factory()->create([
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(48)->addMinutes(20),
    ]);
    Appointment::factory()->create([ // would be due at 24h, not at 48h → skipped
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(24)->addMinutes(20),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect($due->fresh()->reminder_sent_at)->not->toBeNull();
});

it('injects the configured message with date and time substituted', function () {
    Mail::fake();
    PracticeSettings::current()->update([
        'reminder_enabled' => true, 'reminder_lead_hours' => 24,
        'reminder_message' => 'Hallo! Termin am {Datum} um {Uhrzeit}. Danke.',
    ]);
    $appt = Appointment::factory()->create([
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(24)->addMinutes(20),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, function (AppointmentReminderMail $mail) use ($appt) {
        return str_contains($mail->reminderMessage, 'Termin am')
            && ! str_contains($mail->reminderMessage, '{Datum}')
            && ! str_contains($mail->reminderMessage, '{Uhrzeit}');
    });
});
