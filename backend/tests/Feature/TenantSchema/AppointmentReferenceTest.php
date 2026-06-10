<?php

use App\Mail\AppointmentCancelledParentMail;
use App\Mail\AppointmentConfirmationMail;
use App\Mail\AppointmentReminderMail;
use App\Models\Tenant\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives a short uppercase reference in the KC-XXXXXX format', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->publicReference())->toMatch('/^KC-[0-9A-F]{6}$/');
});

it('gives different references to different appointments', function () {
    // Regression guard: uuid7 ids created in the same millisecond share their
    // timestamp PREFIX — a prefix-based derivation would collide here.
    $a = Appointment::factory()->create();
    $b = Appointment::factory()->create();

    expect($a->publicReference())->not->toBe($b->publicReference());
});

it('is stable across reads', function () {
    $appointment = Appointment::factory()->create();
    $fresh = Appointment::findOrFail($appointment->id);

    expect($fresh->publicReference())->toBe($appointment->publicReference());
});

it('renders the reference in the three parent emails', function () {
    $appointment = Appointment::factory()->create();
    $appointment->load(['practitioner', 'service']);
    $ref = $appointment->publicReference();

    expect((new AppointmentConfirmationMail($appointment, 'Praxis', 'https://x.test/storno/t'))->render())->toContain($ref);
    expect((new AppointmentReminderMail($appointment, 'Praxis', 'https://x.test/storno/t'))->render())->toContain($ref);
    expect((new AppointmentCancelledParentMail($appointment, 'Praxis'))->render())->toContain($ref);
});
