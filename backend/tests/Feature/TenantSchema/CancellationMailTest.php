<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Support\Facades\Mail;

it('notifies the cabinet when a parent cancels via the API', function () {
    Mail::fake();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);

    $this->postJson("/api/v1/widget/appointments/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.test'));
});

it('does not re-notify when cancelling an already cancelled appointment via the API', function () {
    Mail::fake();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'cancelled',
    ]);

    $this->postJson("/api/v1/widget/appointments/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    Mail::assertNothingQueued();
});
