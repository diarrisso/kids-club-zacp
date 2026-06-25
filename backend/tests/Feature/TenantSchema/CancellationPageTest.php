<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\PracticeSettings;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function stornoAppointment(string $status = 'confirmed'): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['name' => 'Prophylaxe']);

    return Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => $status,
    ]);
}

function stornoUrl(Appointment $a): string
{
    return "/storno/{$a->cancellation_token}";
}

it('shows the cancellation page with the appointment details', function () {
    $a = stornoAppointment();

    $this->get(stornoUrl($a))
        ->assertOk()
        ->assertSee('Prophylaxe')
        ->assertSee('Termin stornieren');
});

it('returns 404 for an unknown token', function () {
    $this->get('/storno/'.Str::uuid())
        ->assertNotFound();
});

it('shows the clinic-local time on the cancellation page, not the UTC-shifted time', function () {
    // The factory stores 09:00 as the clinic wall clock; the page must show 09:00,
    // never 11:00 (the +02:00 shift from converting a UTC-read value to Berlin).
    $a = stornoAppointment();

    $this->get(stornoUrl($a))
        ->assertOk()
        ->assertSee('09:00 Uhr')
        ->assertDontSee('11:00 Uhr');
});

it('cancels the appointment from the page and notifies the cabinet', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_cancellation' => true]);
    $a = stornoAppointment();

    $this->post(stornoUrl($a))
        ->assertOk()
        ->assertSee('storniert');

    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.test'));
});

it('does not re-cancel or re-notify an already cancelled appointment', function () {
    Mail::fake();
    $a = stornoAppointment('cancelled');

    $this->post(stornoUrl($a))->assertOk();

    Mail::assertNothingQueued();
});

// ── notify_on_cancellation toggle — storno path ──────────────────────────────

it('notifies the cabinet via storno path when notify_on_cancellation is enabled', function () {
    Mail::fake();
    config()->set('mail.practice_notification_address', 'praxis@kidsclub.test');
    PracticeSettings::current()->update(['notify_on_cancellation' => true]);

    $a = stornoAppointment();

    $this->post(stornoUrl($a))->assertOk();

    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.test'));
});

it('does not notify the cabinet via storno path when notify_on_cancellation is disabled', function () {
    Mail::fake();
    config()->set('mail.practice_notification_address', 'praxis@kidsclub.test');
    PracticeSettings::current()->update(['notify_on_cancellation' => false]);

    $a = stornoAppointment();

    $this->post(stornoUrl($a))->assertOk();

    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertNotQueued(AppointmentCancelledMail::class);
});
