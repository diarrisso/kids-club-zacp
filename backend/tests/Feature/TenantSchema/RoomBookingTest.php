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
    $a = new Appointment();

    expect($a->isFillable('room'))->toBeTrue()
        ->and($a->isFillable('notes_internal'))->toBeFalse()
        ->and($a->isFillable('reminder_sent_at'))->toBeFalse();
});
