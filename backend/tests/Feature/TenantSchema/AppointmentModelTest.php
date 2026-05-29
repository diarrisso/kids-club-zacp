<?php
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Support\Str;

it('creates an appointment with a uuid id and cancellation token', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $a = Appointment::create([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => '2026-09-01 09:00:00',
        'ends_at' => '2026-09-01 09:30:00',
        'patient_first_name' => 'Lina',
        'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna',
        'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de',
        'parent_consent_at' => now(),
        'cancellation_token' => (string) Str::uuid(),
    ]);

    expect($a->id)->toBeString()->toHaveLength(36)
        ->and($a->status)->toBe('confirmed')
        ->and($a->practitioner->id)->toBe($p->id);
});

it('stores reminder_sent_at as a nullable datetime', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
    ]);
    expect($a->reminder_sent_at)->toBeNull();

    $a->reminder_sent_at = now();
    $a->save();
    expect($a->fresh()->reminder_sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
