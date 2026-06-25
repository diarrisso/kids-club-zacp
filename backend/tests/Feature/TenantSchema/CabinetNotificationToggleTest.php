<?php

use App\Mail\AppointmentBookedMail;
use App\Mail\AppointmentCancelledMail;
use App\Models\PracticeSettings;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => config()->set('mail.practice_notification_address', 'praxis@example.test'));

// ── Booking notification toggle ─────────────────────────────────────────────

it('notifies the cabinet on a new booking when enabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_booking' => true]);

    [$p, $s, $startsAt] = bookingSetup();

    $this->postJson('/api/v1/widget/appointments', bookingPayload([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertCreated();

    Mail::assertQueued(AppointmentBookedMail::class);
});

it('does not notify the cabinet on booking when disabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_booking' => false]);

    [$p, $s, $startsAt] = bookingSetup();

    $this->postJson('/api/v1/widget/appointments', bookingPayload([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertCreated();

    Mail::assertNotQueued(AppointmentBookedMail::class);
});

// ── Cancellation notification toggle ────────────────────────────────────────

it('respects the cancellation notification toggle', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_cancellation' => false]);

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'status' => 'confirmed',
    ]);

    $this->postJson("/api/v1/widget/appointments/{$a->cancellation_token}/cancel")
        ->assertOk();

    Mail::assertNotQueued(AppointmentCancelledMail::class);
});
