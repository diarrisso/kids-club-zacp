<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\User;

it('creates a recurring availability', function () {
    $p = Practitioner::factory()->create();

    $a = Availability::create([
        'practitioner_id' => $p->id,
        'day_of_week' => 1, // Monday
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    expect($a->fresh()->practitioner->id)->toBe($p->id)
        ->and($a->start_time->format('H:i'))->toBe('09:00');
});

it('rejects end_time before start_time', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'day_of_week' => 1,
            'start_time' => '17:00',
            'end_time' => '09:00',
        ])
        ->assertSessionHasErrors('end_time');
});
