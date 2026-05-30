<?php

use App\Mail\AppointmentReminderMail;
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

// Create a confirmed appointment in the CURRENT tenant context starting at $startsAt.
function reminderAppointment(CarbonImmutable $startsAt, string $status = 'confirmed', ?CarbonImmutable $reminderSentAt = null): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    return Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(30),
        'status' => $status, 'reminder_sent_at' => $reminderSentAt,
        'parent_email' => 'anna@example.de',
    ]);
}

it('queues a reminder for an appointment ~24h out and marks it sent', function () {
    Mail::fake();
    $a = reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30));

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, fn ($m) => $m->hasTo('anna@example.de'));

    tenancy()->initialize($this->tenant);
    expect($a->fresh()->reminder_sent_at)->not->toBeNull();
});

it('does not queue a reminder for an appointment more than 25h out', function () {
    Mail::fake();
    reminderAppointment(CarbonImmutable::now()->addHours(26));

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not re-send a reminder that was already sent', function () {
    Mail::fake();
    reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30), 'confirmed', CarbonImmutable::now());

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('ignores cancelled appointments', function () {
    Mail::fake();
    reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30), 'cancelled');

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('only reminds within the tenant that owns the appointment', function () {
    Mail::fake();
    tenancy()->end(); // leave the default test tenant

    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);
    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    tenancy()->initialize($tenantA);
    $aId = reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30))->id;
    tenancy()->end();

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, 1); // exactly one, from tenant A

    tenancy()->initialize($tenantA);
    expect(Appointment::find($aId)->reminder_sent_at)->not->toBeNull();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(Appointment::count())->toBe(0);
    tenancy()->end();
});
