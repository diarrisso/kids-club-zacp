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

    return Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::parse('2026-09-07 09:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-09-07 09:30', 'Europe/Berlin'),
        'patient_first_name' => 'Lina', 'parent_first_name' => 'Sven',
    ])->load(['service', 'practitioner']);
}

it('renders the confirmation mail in German with the cancel link', function () {
    $html = (new AppointmentConfirmationMail(mailAppointment(), 'Kids Club', 'https://x.test/storno/t/abc'))->render();

    expect($html)
        ->toContain('bestätigt')
        ->toContain('Prophylaxe')
        ->toContain('Lina')
        ->toContain('https://x.test/storno/t/abc');
});

it('renders the reminder mail in German with the cancel link', function () {
    $html = (new AppointmentReminderMail(mailAppointment(), 'Kids Club', 'https://x.test/storno/t/abc'))->render();

    expect($html)->toContain('Erinnerung')->toContain('Prophylaxe')->toContain('https://x.test/storno/t/abc');
});

it('renders the cancelled mail for the cabinet', function () {
    $html = (new AppointmentCancelledMail(mailAppointment(), 'Kids Club'))->render();

    expect($html)->toContain('storniert')->toContain('Prophylaxe')->toContain('Lina');
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
        ->not->toContain('INTERNAL-ONLY-SENTINEL');   // notes_internal must not leak
});
