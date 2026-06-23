<?php

use App\Mail\AppointmentRescheduledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\AppointmentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function rescheduleStaff(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

// A future, conflict-free appointment with a parent email.
function makeAppointment(array $overrides = []): Appointment
{
    $start = CarbonImmutable::now('Europe/Berlin')->addDays(3)->setTime(10, 0);

    return AppointmentFactory::new()->create(array_merge([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(30),
        'status' => 'confirmed',
        'parent_email' => 'eltern@example.com',
    ], $overrides));
}

it('emails the parent when the appointment time changes', function () {
    $appt = makeAppointment();
    $newStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin')->addDays(1);

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])
        ->assertOk();

    Mail::assertQueued(AppointmentRescheduledMail::class, fn ($m) => $m->hasTo('eltern@example.com'));
});

it('emails the parent when the practitioner changes (same time)', function () {
    $appt = makeAppointment();
    $other = Practitioner::factory()->create();

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'practitioner_id' => $other->id,
        ])
        ->assertOk();

    Mail::assertQueued(AppointmentRescheduledMail::class);
});

it('does not email when only attendance or internal notes change', function () {
    $appt = makeAppointment();

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'attendance' => 'arrived',
            'notes_internal' => 'Kind war ruhig',
        ])
        ->assertOk();

    Mail::assertNotQueued(AppointmentRescheduledMail::class);
});

it('does not email when the appointment has no parent email', function () {
    $appt = makeAppointment(['parent_email' => null]);
    $newStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin')->addDays(1);

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])
        ->assertOk();

    Mail::assertNotQueued(AppointmentRescheduledMail::class);
});

it('carries the old start, the new start and the storno link to the mailable', function () {
    $appt = makeAppointment();
    $oldStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin');
    $newStart = $oldStart->addDays(2);

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])
        ->assertOk();

    Mail::assertQueued(AppointmentRescheduledMail::class, function ($m) use ($appt, $oldStart, $newStart) {
        expect($m->oldStart->format('Y-m-d H:i'))->toBe($oldStart->format('Y-m-d H:i'));
        expect($m->appointment->clinicStartsAt()->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'));
        expect($m->cancelUrl)->toContain($appt->cancellation_token);

        return true;
    });
});

it('renders the rescheduled email without error (old -> new, Berlin times, storno button)', function () {
    $appt = makeAppointment();
    $oldStart = $appt->clinicStartsAt();
    $newStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin')->addDays(1);
    $appt->update(['starts_at' => $newStart, 'ends_at' => $newStart->addMinutes(30)]);

    $mail = new AppointmentRescheduledMail(
        $appt->fresh(['service', 'practitioner']),
        'Kids Club',
        'https://example.test/storno/abc',
        $oldStart,
        'Dr. Anna Müller',
    );

    $rendered = $mail->render();

    expect($rendered)
        ->toContain('verschoben')
        ->toContain($oldStart->format('H:i'))
        ->toContain($newStart->format('H:i'))
        ->toContain('https://example.test/storno/abc');
});
