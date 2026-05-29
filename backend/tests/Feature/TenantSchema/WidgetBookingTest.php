<?php
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

function bookingPayload(array $override = []): array
{
    return array_merge([
        'practitioner_id' => null, 'service_id' => null,
        'starts_at' => '2026-09-07 09:00:00',
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '', // honeypot empty
    ], $override);
}

function bookUrl(): string
{
    return 'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments';
}

it('books an appointment for a child', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->postJson(bookUrl(), bookingPayload(['practitioner_id' => $p->id, 'service_id' => $s->id]))
        ->assertCreated()
        ->assertJsonStructure(['cancellation_token', 'starts_at', 'ends_at']);

    tenancy()->initialize($this->tenant);
    $a = Appointment::firstOrFail();
    expect($a->status)->toBe('confirmed')
        ->and($a->ends_at->format('H:i'))->toBe('09:30')
        ->and($a->parent_consent_at)->not->toBeNull();
});

it('rejects booking without consent', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'consent' => false,
    ]))->assertStatus(422)->assertJsonValidationErrors('consent');
});

it('silently drops a booking when the honeypot is filled', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'website' => 'http://spam.test',
    ]))->assertOk();

    tenancy()->initialize($this->tenant);
    expect(Appointment::count())->toBe(0);
});

it('blocks a double booking on the same slot', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => '2026-09-07 09:00:00', 'ends_at' => '2026-09-07 09:30:00', 'status' => 'confirmed',
    ]);

    $this->postJson(bookUrl(), bookingPayload(['practitioner_id' => $p->id, 'service_id' => $s->id]))
        ->assertStatus(409);
});
