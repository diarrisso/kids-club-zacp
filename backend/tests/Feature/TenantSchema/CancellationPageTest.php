<?php

use App\Mail\AppointmentCancelledMail;
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

it('cancels the appointment from the page and notifies the cabinet', function () {
    Mail::fake();
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
