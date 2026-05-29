<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminderMail;
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Queue a 24h reminder email for each upcoming confirmed appointment (all tenants).';

    public function handle(): int
    {
        Tenant::all()->each(function (Tenant $tenant) {
            $tenant->run(function () {
                Appointment::query()
                    ->where('status', 'confirmed')
                    ->whereNull('reminder_sent_at')
                    ->whereBetween('starts_at', [now()->addHours(24), now()->addHours(25)])
                    ->with(['service', 'practitioner'])
                    ->get()
                    ->each(function (Appointment $appointment) {
                        try {
                            $cancelUrl = route('storno.show', [
                                'tenant' => tenant()->getTenantKey(),
                                'token' => $appointment->cancellation_token,
                            ]);

                            // Mark-then-send: persist reminder_sent_at first, so if the
                            // queue push fails we roll it back and retry next run — rather
                            // than queueing first and risking a duplicate reminder if the
                            // save then failed. (Field is not $fillable → direct assignment.)
                            $appointment->reminder_sent_at = now();
                            $appointment->save();

                            try {
                                Mail::to($appointment->parent_email)->queue(
                                    new AppointmentReminderMail($appointment, tenant()->name, $cancelUrl)
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
            });
        });

        return self::SUCCESS;
    }
}
