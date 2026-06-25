<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\PracticeSettings;
use Illuminate\Support\Facades\Mail;

it('skips the parent confirmation mail when disabled but still books', function () {
    Mail::fake();
    PracticeSettings::current()->update(['booking_confirmation_enabled' => false]);

    [$p, $s, $startsAt] = bookingSetup();

    $payload = bookingPayload([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]);

    $this->postJson(bookUrl(), $payload)->assertCreated();

    Mail::assertNotQueued(AppointmentConfirmationMail::class);
});

it('sends the parent confirmation mail when enabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['booking_confirmation_enabled' => true]);

    [$p, $s, $startsAt] = bookingSetup();

    $payload = bookingPayload([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]);

    $this->postJson(bookUrl(), $payload)->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class);
});
