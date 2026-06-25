<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminderMail;
use App\Models\PracticeSettings;
use App\Models\Tenant\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Queue a reminder email for each upcoming confirmed appointment, honoring practice settings.';

    public function handle(): int
    {
        $settings = PracticeSettings::current();

        if (! $settings->reminder_enabled) {
            return self::SUCCESS;
        }

        $lead = $settings->reminder_lead_hours;

        Appointment::query()
            ->where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>=', now()->addHours($lead))
            ->where('starts_at', '<', now()->addHours($lead + 1))
            ->with(['service', 'practitioner'])
            ->get()
            ->each(function (Appointment $appointment) use ($settings) {
                try {
                    $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);

                    $message = str_replace(
                        ['{Datum}', '{Uhrzeit}'],
                        [
                            $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y'),
                            $appointment->clinicStartsAt()->format('H:i').' Uhr',
                        ],
                        $settings->reminder_message,
                    );

                    // Mark-then-send: persist reminder_sent_at first, so if the
                    // queue push fails we roll it back and retry next run — rather
                    // than queueing first and risking a duplicate reminder if the
                    // save then failed. (Field is not $fillable → direct assignment.)
                    $appointment->reminder_sent_at = now();
                    $appointment->save();

                    try {
                        Mail::to($appointment->parent_email)->queue(
                            new AppointmentReminderMail($appointment, config('app.name'), $cancelUrl, $message)
                        );
                    } catch (\Throwable $e) {
                        $appointment->reminder_sent_at = null;
                        $appointment->save();
                        throw $e; // surface to the outer catch for reporting
                    }
                } catch (\Throwable $e) {
                    // One bad appointment must not abort the whole batch.
                    report($e);
                }
            });

        return self::SUCCESS;
    }
}
