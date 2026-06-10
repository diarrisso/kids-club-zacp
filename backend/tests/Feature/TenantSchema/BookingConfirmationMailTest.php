<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function confirmBookingSetup(): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $s = Service::factory()->create(['duration_minutes' => 30, 'is_active' => true]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
    $startsAt = CarbonImmutable::parse($monday->toDateString().' 09:00', 'Europe/Berlin');

    return [$p, $s, $startsAt];
}

it('queues a confirmation mail to the parent after a successful booking', function () {
    Mail::fake();
    [$p, $s, $startsAt] = confirmBookingSetup();

    $this->postJson('/api/v1/widget/appointments', [
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '',
    ])->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class, fn ($m) => $m->hasTo('anna@example.de'));
});

it('renders the clinic-local time in the confirmation mail, not the UTC-shifted time', function () {
    [$p, $s] = confirmBookingSetup();

    // appointments.starts_at holds the clinic wall clock (Europe/Berlin) in a plain
    // `timestamp` column (see AppointmentController::toClinicIso). A 09:00 booking
    // must therefore render as 09:00 in the mail — never 11:00 (the +02:00 shift you
    // get by *converting* a UTC-read value to Berlin instead of re-labelling it).
    $appointment = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => '2026-09-07 09:00:00', 'ends_at' => '2026-09-07 09:30:00',
    ]);

    $html = (new AppointmentConfirmationMail($appointment, 'Kids Club', 'https://example.test/storno/x'))->render();

    expect($html)->toContain('09:00')->not->toContain('11:00');
});

it('does not queue a confirmation mail when the honeypot is filled', function () {
    Mail::fake();
    [$p, $s, $startsAt] = confirmBookingSetup();

    $this->postJson('/api/v1/widget/appointments', [
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'consent' => true, 'website' => 'http://spam.test',
    ])->assertOk();

    Mail::assertNothingQueued();
});
