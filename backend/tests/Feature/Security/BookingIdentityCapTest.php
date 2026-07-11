<?php

/**
 * Anti calendar-squatting: a single parent identity (same email OR phone) may
 * hold at most config('booking.max_active_per_identity') active future
 * appointments. Tests lower the cap via config to stay cheap and deterministic.
 */

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

// Unique helper names (Pest loads every test file into one global scope).
function capBookableMonday(): CarbonImmutable
{
    return CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
}

function capBookingSetup(): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $s = Service::factory()->create(['duration_minutes' => 30, 'is_active' => true]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    $startsAt = CarbonImmutable::parse(capBookableMonday()->toDateString().' 09:00', 'Europe/Berlin');

    return [$p, $s, $startsAt];
}

function capPayload(array $override = []): array
{
    return array_merge([
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '',
    ], $override);
}

// A far-future active appointment for an arbitrary identity (does not collide
// with the bookable Monday slot the API attempt uses).
function capExistingAppointment(array $override = []): Appointment
{
    $future = CarbonImmutable::now()->addWeeks(3);

    return Appointment::factory()->create(array_merge([
        'parent_email' => 'anna@example.de',
        'parent_phone' => '+49 170 0000000',
        'starts_at' => $future,
        'ends_at' => $future->addMinutes(30),
        'status' => 'confirmed',
    ], $override));
}

it('refuses a booking once the identity already holds the cap (by email)', function () {
    config(['booking.max_active_per_identity' => 2]);
    [$p, $s, $startsAt] = capBookingSetup();

    // Distinct phones so ONLY the shared email links these to the booking payload —
    // otherwise the default phone alone would reach the cap and the test would pass
    // even if email matching were broken.
    capExistingAppointment(['parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000001']);
    capExistingAppointment(['parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000002']);

    $this->postJson('/api/v1/widget/appointments', capPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))
        ->assertStatus(422)
        // Contract the widget depends on: a field validation error (not a bare
        // abort message) so the parent actually sees why the booking was refused.
        ->assertJsonValidationErrors(['parent_email']);

    // The capped slot itself was never written.
    expect(Appointment::where('starts_at', $startsAt)->count())->toBe(0);
});

it('counts the cap by phone as well as email', function () {
    config(['booking.max_active_per_identity' => 1]);
    [$p, $s, $startsAt] = capBookingSetup();

    // Shares the PHONE but a different email — must still count toward the cap.
    capExistingAppointment([
        'parent_email' => 'someone-else@example.de',
        'parent_phone' => '+49 170 0000000',
    ]);

    $this->postJson('/api/v1/widget/appointments', capPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'parent_email' => 'brand-new@example.de', // different email
        'parent_phone' => '+49 170 0000000',      // same phone
    ]))->assertStatus(422);
});

it('matches the email case-insensitively', function () {
    config(['booking.max_active_per_identity' => 1]);
    [$p, $s, $startsAt] = capBookingSetup();

    capExistingAppointment(['parent_email' => 'anna@example.de', 'parent_phone' => '+49 000']);

    $this->postJson('/api/v1/widget/appointments', capPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'parent_email' => 'ANNA@Example.DE', // same identity, different case
        'parent_phone' => '+49 999',         // different phone
    ]))->assertStatus(422);
});

it('ignores cancelled and past appointments when counting the cap', function () {
    config(['booking.max_active_per_identity' => 1]);
    [$p, $s, $startsAt] = capBookingSetup();

    capExistingAppointment(['status' => 'cancelled']); // future but cancelled
    $past = CarbonImmutable::now()->subWeek();
    capExistingAppointment(['starts_at' => $past, 'ends_at' => $past->addMinutes(30)]); // past

    $this->postJson('/api/v1/widget/appointments', capPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertCreated();
});

it('does not count other parents toward this identity cap', function () {
    config(['booking.max_active_per_identity' => 1]);
    [$p, $s, $startsAt] = capBookingSetup();

    capExistingAppointment([
        'parent_email' => 'other-family@example.de',
        'parent_phone' => '+49 160 9999999',
    ]);

    $this->postJson('/api/v1/widget/appointments', capPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertCreated();
});

it('lets the honeypot fake-success win over the identity cap (no leak)', function () {
    config(['booking.max_active_per_identity' => 1]);
    [$p, $s, $startsAt] = capBookingSetup();
    capExistingAppointment();

    // Honeypot filled: must return the silent 200 fake-success, never the 422 cap.
    $this->postJson('/api/v1/widget/appointments', capPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'website' => 'http://spam.test',
    ]))->assertOk();
});
