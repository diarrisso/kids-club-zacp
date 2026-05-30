<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Support\CabinetNotifier;
use Illuminate\Support\Facades\Mail;

it('returns the configured cabinet notification address', function () {
    // PRACTICE_NOTIFICATION_EMAIL is set to praxis@kidsclub.test in phpunit.xml.
    expect(CabinetNotifier::recipients())->toBe(['praxis@kidsclub.test']);
});

it('queues a cancellation mail to the configured cabinet recipient', function () {
    Mail::fake();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create(['practitioner_id' => $p->id, 'service_id' => $s->id]);

    CabinetNotifier::notifyCancelled($a);

    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.test'));
});

it('queues nothing when no cabinet address is configured', function () {
    Mail::fake();
    config()->set('mail.practice_notification_address', null);

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create(['practitioner_id' => $p->id, 'service_id' => $s->id]);

    CabinetNotifier::notifyCancelled($a);

    Mail::assertNothingQueued();
});
