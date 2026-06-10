<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

function widgetBookableMonday(): CarbonImmutable
{
    return CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
}

// practitioner + linked service + Monday 09:00-17:00 availability; returns [practitioner, service, monday 09:00]
function bookingSetup(int $duration = 30): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $s = Service::factory()->create(['duration_minutes' => $duration, 'is_active' => true]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    $startsAt = CarbonImmutable::parse(widgetBookableMonday()->toDateString().' 09:00', 'Europe/Berlin');

    return [$p, $s, $startsAt];
}

function bookingPayload(array $override = []): array
{
    return array_merge([
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '',
    ], $override);
}

function bookUrl(): string
{
    return '/api/v1/widget/appointments';
}

it('books an appointment for a child', function () {
    [$p, $s, $startsAt] = bookingSetup();

    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertCreated()->assertJsonStructure(['reference', 'cancellation_token', 'starts_at', 'ends_at']);

    $a = Appointment::firstOrFail();
    expect($a->status)->toBe('confirmed')->and($a->parent_consent_at)->not->toBeNull();
});

it('rejects booking without consent', function () {
    [$p, $s, $startsAt] = bookingSetup();
    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'consent' => false,
    ]))->assertStatus(422)->assertJsonValidationErrors('consent');
});

it('silently drops a booking when the honeypot is filled', function () {
    [$p, $s, $startsAt] = bookingSetup();
    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'website' => 'http://spam.test',
    ]))->assertOk();
    expect(Appointment::count())->toBe(0);
});

it('blocks a double booking on the same slot', function () {
    [$p, $s, $startsAt] = bookingSetup();
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(30), 'status' => 'confirmed',
    ]);
    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertStatus(409);
});

it('rejects a booking outside the practitioner availability (422)', function () {
    [$p, $s] = bookingSetup();
    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::parse(widgetBookableMonday()->toDateString().' 20:00', 'Europe/Berlin')->format('Y-m-d H:i:s'), // after 17:00
    ]))->assertStatus(422);
});

it('rejects a booking in the past (422)', function () {
    [$p, $s] = bookingSetup();
    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::now()->subDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
    ]))->assertStatus(422);
});

it('rejects a booking for a service the practitioner does not offer (422)', function () {
    [$p, $s, $startsAt] = bookingSetup();
    $other = Service::factory()->create(['duration_minutes' => 30, 'is_active' => true]); // not attached to $p
    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $other->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertStatus(422);
});
