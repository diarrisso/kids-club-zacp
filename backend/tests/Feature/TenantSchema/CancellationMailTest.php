<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('notifies the cabinet when a parent cancels via the API', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);

    $this->postJson("http://central.masinga-booking.test/api/v1/widget/testtenant/appointments/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.de'));
});

it('does not re-notify when cancelling an already cancelled appointment via the API', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'cancelled',
    ]);

    $this->postJson("http://central.masinga-booking.test/api/v1/widget/testtenant/appointments/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    Mail::assertNothingQueued();
});
