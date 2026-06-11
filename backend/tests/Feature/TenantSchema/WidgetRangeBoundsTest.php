<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = Service::factory()->create(['is_active' => true]);
});

// Builds a valid StoreAppointmentRequest payload EXCEPT starts_at, which is supplied
// by the caller. A practitioner offering the service is created so the foreign-key /
// existence rules pass and validation is the only thing under test.
// Uniquely named (not bookingPayload) to avoid colliding with the global helper in
// WidgetBookingTest.php — Pest loads every test file into the same function namespace.
function rangeBoundsPayload(Service $service, string $startsAt): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $service->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    return [
        'practitioner_id' => $p->id,
        'service_id' => $service->id,
        'starts_at' => $startsAt,
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '',
    ];
}

it('rejects a slots range wider than 62 days', function () {
    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-03-20', // 78 days
    ]))->assertStatus(422);
});

it('accepts a slots range of exactly 62 days', function () {
    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-03-04', // 62 days
    ]))->assertOk();
});

it('rejects a slots range of 63 days (the first day over the cap)', function () {
    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-03-05', // 63 days
    ]))->assertStatus(422);
});

it('rejects an availability/days range wider than 62 days', function () {
    $this->getJson('/api/v1/widget/availability/days?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-12-31',
    ]))->assertStatus(422);
});

it('rejects a booking whose starts_at is in the past', function () {
    $payload = rangeBoundsPayload($this->service, now()->subDay()->toIso8601String());
    $this->postJson('/api/v1/widget/appointments', $payload)->assertJsonValidationErrors('starts_at');
});

it('rejects a booking whose starts_at is beyond the horizon', function () {
    $payload = rangeBoundsPayload($this->service, now()->addDays(90)->toIso8601String());
    $this->postJson('/api/v1/widget/appointments', $payload)->assertJsonValidationErrors('starts_at');
});
