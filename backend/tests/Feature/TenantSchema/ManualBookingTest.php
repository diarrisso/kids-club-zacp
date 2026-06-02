<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function manualPayload(Practitioner $p, Service $s, array $override = []): array
{
    return array_merge([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin')->format('Y-m-d H:i:s'),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller', 'parent_phone' => '+49 170 0',
    ], $override);
}

it('creates a manual appointment without email and queues no confirmation', function () {
    Mail::fake();
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s))
        ->assertCreated();

    Mail::assertNothingQueued();
    expect(Appointment::whereNull('parent_email')->count())->toBe(1);
});

it('queues a confirmation when a parent email is provided', function () {
    Mail::fake();
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s, ['parent_email' => 'anna@example.de']))
        ->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class, fn ($m) => $m->hasTo('anna@example.de'));
});

it('persists notes_internal even though it is not mass-assignable', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s, ['notes_internal' => 'Allergie Penicillin']))
        ->assertCreated();

    expect(Appointment::first()->notes_internal)->toBe('Allergie Penicillin');
});

it('rejects a manual booking overlapping the same practitioner (409)', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin');
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s))
        ->assertStatus(409);
});
