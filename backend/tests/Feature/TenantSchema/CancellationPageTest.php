<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\Tenant;
use App\Models\User;
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
    return "http://central.masinga-booking.test/storno/testtenant/{$a->cancellation_token}";
}

it('shows the cancellation page with the appointment details', function () {
    $a = stornoAppointment();

    $this->get(stornoUrl($a))
        ->assertOk()
        ->assertSee('Prophylaxe')
        ->assertSee('Termin stornieren');
});

it('returns 404 for an unknown token', function () {
    $this->get('http://central.masinga-booking.test/storno/testtenant/'.Str::uuid())
        ->assertNotFound();
});

it('cancels the appointment from the page and notifies the cabinet', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $a = stornoAppointment();

    $this->post(stornoUrl($a))
        ->assertOk()
        ->assertSee('storniert');

    tenancy()->initialize($this->tenant);
    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.de'));
});

it('does not re-cancel or re-notify an already cancelled appointment', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $a = stornoAppointment('cancelled');

    $this->post(stornoUrl($a))->assertOk();

    Mail::assertNothingQueued();
});

it('returns 404 when a valid token is used under a different tenant slug', function () {
    $a = stornoAppointment(); // created under the default 'testtenant' schema

    // A second real tenant whose schema does NOT contain this token.
    $other = Tenant::factory()->create(['id' => 'othertenant']);
    $other->domains()->create(['domain' => 'othertenant.masinga-booking.test', 'is_primary' => true]);

    $this->get("http://central.masinga-booking.test/storno/othertenant/{$a->cancellation_token}")
        ->assertNotFound();
});
