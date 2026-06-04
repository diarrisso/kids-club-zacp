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
