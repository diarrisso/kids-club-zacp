<?php

use App\Mail\AppointmentReminderMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function reminderAppointment(CarbonImmutable $startsAt, string $status = 'confirmed', ?CarbonImmutable $reminderSentAt = null): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    return Appointment::factory()->create([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'status' => $status,
        'reminder_sent_at' => $reminderSentAt,
    ]);
}

it('queues a reminder for a confirmed appointment ~24h out and marks it sent', function () {
    Mail::fake();
    $startsAt = CarbonImmutable::now()->addHours(24)->addMinutes(30);
    $a = reminderAppointment($startsAt);

    $this->artisan('appointments:send-reminders')->assertExitCode(0);

    expect($a->fresh()->reminder_sent_at)->not->toBeNull();
    Mail::assertQueued(AppointmentReminderMail::class, fn ($m) => $m->hasTo($a->parent_email));
});

it('does not queue a reminder outside the 24-25h window', function () {
    Mail::fake();
    $startsAt = CarbonImmutable::now()->addHours(26);
    reminderAppointment($startsAt);

    $this->artisan('appointments:send-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('does not send a second reminder if already sent', function () {
    Mail::fake();
    $startsAt = CarbonImmutable::now()->addHours(24)->addMinutes(30);
    reminderAppointment($startsAt, 'confirmed', CarbonImmutable::now());

    $this->artisan('appointments:send-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('skips cancelled appointments', function () {
    Mail::fake();
    $startsAt = CarbonImmutable::now()->addHours(24)->addMinutes(30);
    reminderAppointment($startsAt, 'cancelled');

    $this->artisan('appointments:send-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
});
