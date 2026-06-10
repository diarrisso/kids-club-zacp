<?php

use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentCancelledParentMail;
use App\Mail\AppointmentConfirmationMail;
use App\Mail\AppointmentReminderMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

function mailAppointment(): Appointment
{
    $p = Practitioner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Berg', 'title' => 'Dr.']);
    $s = Service::factory()->create(['name' => 'Prophylaxe']);

    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::parse('2026-09-07 09:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-09-07 09:30', 'Europe/Berlin'),
        'patient_first_name' => 'Lina', 'parent_first_name' => 'Sven',
    ]);

    // Re-read from the DB so starts_at comes back as a UTC-tz Carbon (the real
    // render condition). With the in-memory Berlin instance the +02:00 conversion
    // bug is masked, so the 09:00/never-11:00 guards below would not catch it.
    return Appointment::with(['service', 'practitioner'])->firstOrFail();
}

it('renders the confirmation mail in German with the cancel link', function () {
    $html = (new AppointmentConfirmationMail(mailAppointment(), 'Kids Club', 'https://x.test/storno/t/abc'))->render();

    expect($html)
        ->toContain('bestätigt')
        ->toContain('Prophylaxe')
        ->toContain('Lina')
        ->toContain('09:00')          // clinic wall clock — never the +02:00-shifted 11:00
        ->not->toContain('11:00')
        ->toContain('https://x.test/storno/t/abc');
});

it('renders the reminder mail in German with the cancel link', function () {
    $html = (new AppointmentReminderMail(mailAppointment(), 'Kids Club', 'https://x.test/storno/t/abc'))->render();

    expect($html)->toContain('Erinnerung')->toContain('Prophylaxe')
        ->toContain('09:00')->not->toContain('11:00')
        ->toContain('https://x.test/storno/t/abc');
});

it('renders the cancelled mail for the cabinet', function () {
    $html = (new AppointmentCancelledMail(mailAppointment(), 'Kids Club'))->render();

    expect($html)->toContain('storniert')->toContain('Prophylaxe')->toContain('Lina')
        ->toContain('09:00')->not->toContain('11:00');
});

it('sets the cabinet name as the from-name on every mail', function () {
    $a = mailAppointment();
    $env = (new AppointmentConfirmationMail($a, 'Kids Club', 'https://x.test'))->envelope();
    expect($env->from->name)->toBe('Kids Club')
        ->and($env->subject)->toContain('Kids Club');
});

it('renders the parent cancellation mail in German without internal data', function () {
    $a = mailAppointment();
    $a->notes_internal = 'INTERNAL-ONLY-SENTINEL';   // staff-only field, must never reach the parent

    $html = (new AppointmentCancelledParentMail($a, 'Kids Club'))->render();

    expect($html)
        ->toContain('storniert')
        ->toContain('Prophylaxe')
        ->toContain('Lina')
        ->toContain('Kids Club')
        ->toContain('09:00')->not->toContain('11:00')
        ->not->toContain('INTERNAL-ONLY-SENTINEL');   // notes_internal must not leak
});
