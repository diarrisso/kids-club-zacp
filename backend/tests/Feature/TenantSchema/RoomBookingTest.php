<?php

use App\Support\Room;

it('maps each room to its KidsClub hex color', function () {
    expect(Room::Green->color())->toBe('#BDCCC2')
        ->and(Room::Yellow->color())->toBe('#F7E29D')
        ->and(Room::Peach->color())->toBe('#FCE8E1')
        ->and(Room::Blue->color())->toBe('#98ACBA')
        ->and(Room::Purple->color())->toBe('#CCC8CE');
});

it('exposes options as value/color/label rows for the front', function () {
    $options = Room::options();

    expect($options)->toHaveCount(5)
        ->and($options[0])->toHaveKeys(['value', 'color', 'label'])
        ->and(collect($options)->pluck('value')->all())
        ->toBe(['green', 'yellow', 'peach', 'blue', 'purple']);
});

use App\Models\Tenant\Appointment;
use Database\Factories\Tenant\AppointmentFactory;

it('stores room as a Room enum and allows null', function () {
    $withRoom = AppointmentFactory::new()->create(['room' => 'blue']);
    $withoutRoom = AppointmentFactory::new()->create(['room' => null]);

    expect($withRoom->fresh()->room)->toBe(Room::Blue)
        ->and($withoutRoom->fresh()->room)->toBeNull();
});

it('keeps notes_internal and reminder_sent_at out of mass assignment', function () {
    $a = new Appointment;

    expect($a->isFillable('room'))->toBeTrue()
        ->and($a->isFillable('notes_internal'))->toBeFalse()
        ->and($a->isFillable('reminder_sent_at'))->toBeFalse();
});

use Database\Factories\Tenant\PractitionerFactory;
use Database\Factories\Tenant\ServiceFactory;

function roomBookingPayload(array $overrides = []): array
{
    $service = ServiceFactory::new()->create(['duration_minutes' => 30, 'is_active' => true]);
    $practitioner = PractitionerFactory::new()->create(['is_active' => true]);
    $practitioner->services()->attach($service->id);

    return array_merge([
        'practitioner_id' => $practitioner->id,
        'service_id' => $service->id,
        // A far-future Monday 09:00 Berlin to satisfy lead/horizon/open-hours
        // is environment-specific; this dataset focuses on validation rules,
        // so we assert the 422 *fields*, not a successful booking.
        'starts_at' => now()->addDays(3)->setTime(9, 0)->toIso8601String(),
        'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
        'patient_birthdate' => '2018-05-01',
        'parent_first_name' => 'Eva', 'parent_last_name' => 'Muster',
        'parent_email' => 'eva@example.com', 'consent' => true,
    ], $overrides);
}

it('rejects a room outside the five allowed values', function () {
    $res = $this->postJson('/api/v1/widget/appointments', roomBookingPayload(['room' => 'rouge']));
    $res->assertStatus(422);
    expect($res->json('errors'))->toHaveKey('room');
});

it('accepts a missing room (room is optional)', function () {
    $res = $this->postJson('/api/v1/widget/appointments', roomBookingPayload());
    // Either booked (no room error) or rejected for scheduling — never a room error.
    expect($res->json('errors.room'))->toBeNull();
});

use App\Models\User;

it('exposes room in the calendar events feed', function () {
    $a = AppointmentFactory::new()->create(['room' => 'purple', 'status' => 'confirmed']);

    $this->actingAs(User::factory()->create())
        ->getJson('/termine/events?start='.$a->starts_at->copy()->subDay()->toDateString()
            .'&end='.$a->ends_at->copy()->addDay()->toDateString()
            .'&practitioner_ids[]='.$a->practitioner_id)
        ->assertOk()
        ->assertJsonFragment(['room' => 'purple']);
});

it('persists room on a manual staff booking', function () {
    $service = ServiceFactory::new()->create(['duration_minutes' => 30]);
    $practitioner = PractitionerFactory::new()->create();

    $this->actingAs(User::factory()->create())
        ->postJson('/termine', [
            'practitioner_id' => $practitioner->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
            'patient_birthdate' => '2018-05-01',
            'parent_first_name' => 'Eva', 'parent_last_name' => 'Muster',
            'parent_phone' => '030 123', 'room' => 'green',
        ])->assertCreated();

    expect(Appointment::latest('id')->first()->room)->toBe(Room::Green);
});

it('rejects an invalid room on a manual staff booking', function () {
    $service = ServiceFactory::new()->create(['duration_minutes' => 30]);
    $practitioner = PractitionerFactory::new()->create();

    $this->actingAs(User::factory()->create())
        ->postJson('/termine', [
            'practitioner_id' => $practitioner->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
            'patient_birthdate' => '2018-05-01',
            'parent_first_name' => 'Eva', 'parent_last_name' => 'Muster',
            'parent_phone' => '030 123', 'room' => 'rouge',
        ])->assertStatus(422)->assertJsonValidationErrors('room');
});
