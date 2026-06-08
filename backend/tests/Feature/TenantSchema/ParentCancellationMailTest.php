<?php

use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentCancelledParentMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function cancellableAppointment(array $overrides = []): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    return Appointment::factory()->create(array_merge([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'status' => 'confirmed',
        'starts_at' => CarbonImmutable::parse('2026-09-07 09:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-09-07 09:30', 'Europe/Berlin'),
        'parent_email' => 'parent@example.de',
    ], $overrides));
}

it('emails the parent (and the cabinet) when cancelled via the storno page', function () {
    Mail::fake();
    $a = cancellableAppointment();

    $this->post("/storno/{$a->cancellation_token}")->assertOk();

    Mail::assertQueued(AppointmentCancelledParentMail::class, fn ($m) => $m->hasTo('parent@example.de'));
    Mail::assertQueued(AppointmentCancelledMail::class); // cabinet alert unchanged
});

it('emails the parent when cancelled via the widget API', function () {
    Mail::fake();
    $a = cancellableAppointment();

    $this->postJson("/api/v1/widget/appointments/{$a->cancellation_token}/cancel")->assertOk();

    Mail::assertQueued(AppointmentCancelledParentMail::class, fn ($m) => $m->hasTo('parent@example.de'));
});

it('emails the parent when cancelled by staff via /termine (no cabinet alert)', function () {
    Mail::fake();
    $a = cancellableAppointment();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/termine/{$a->id}")
        ->assertOk();

    Mail::assertQueued(AppointmentCancelledParentMail::class, fn ($m) => $m->hasTo('parent@example.de'));
    Mail::assertNotQueued(AppointmentCancelledMail::class); // staff cancel: no cabinet self-alert
});

it('does not email a parent without an address, but still cancels', function () {
    Mail::fake();
    $a = cancellableAppointment(['parent_email' => null]);

    $this->actingAs(User::factory()->create())
        ->deleteJson("/termine/{$a->id}")
        ->assertOk();

    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertNotQueued(AppointmentCancelledParentMail::class);
});

it('does not re-email on an already-cancelled appointment (idempotent)', function () {
    Mail::fake();
    $a = cancellableAppointment(['status' => 'cancelled']);

    $this->post("/storno/{$a->cancellation_token}")->assertOk();

    Mail::assertNotQueued(AppointmentCancelledParentMail::class);
});
