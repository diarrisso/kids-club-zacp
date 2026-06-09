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
            'days' => [1],
            'start_time' => '17:00',
            'end_time' => '09:00',
            'valid_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('end_time');
});

it('creates one availability per selected day (mode A, same hours)', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'days' => [1, 3, 5],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->addMonths(3)->toDateString(),
            'slot_interval_minutes' => 30,
        ])
        ->assertRedirect('/sprechzeiten')
        ->assertSessionHasNoErrors();

    $rows = Availability::where('practitioner_id', $p->id)->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('day_of_week')->sort()->values()->all())->toBe([1, 3, 5]);
    expect($rows->every(fn ($a) => $a->start_time->format('H:i') === '09:00'))->toBeTrue();
    expect($rows->every(fn ($a) => $a->slot_interval_minutes === 30))->toBeTrue();
    expect($rows->every(fn ($a) => $a->valid_to !== null))->toBeTrue();
});

it('creates one availability per day with per-day hours (mode B)', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'days_hours' => [
                1 => ['start' => '08:00', 'end' => '12:00'],
                2 => ['start' => '13:00', 'end' => '17:00'],
            ],
            'valid_from' => now()->toDateString(),
            'valid_to' => null,
        ])
        ->assertRedirect('/sprechzeiten')
        ->assertSessionHasNoErrors();

    expect(Availability::where('practitioner_id', $p->id)->count())->toBe(2);
    expect(Availability::where('practitioner_id', $p->id)->where('day_of_week', 1)->first()->start_time->format('H:i'))->toBe('08:00');
    expect(Availability::where('practitioner_id', $p->id)->where('day_of_week', 2)->first()->start_time->format('H:i'))->toBe('13:00');
    expect(Availability::where('practitioner_id', $p->id)->where('day_of_week', 1)->first()->valid_to)->toBeNull();
});

it('rejects bulk submission with no days selected', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'days' => [],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('days');
});

it('still updates a single availability via PUT (edit path unaffected)', function () {
    $p = Practitioner::factory()->create();
    $a = Availability::create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    $this->actingAs(User::factory()->create())
        ->put("/sprechzeiten/{$a->id}", [
            'practitioner_id' => $p->id,
            'day_of_week' => 2,
            'start_time' => '10:00',
            'end_time' => '16:00',
            'slot_interval_minutes' => 20,
        ])
        ->assertRedirect('/sprechzeiten')
        ->assertSessionHasNoErrors();

    $a->refresh();
    expect($a->day_of_week)->toBe(2);
    expect($a->start_time->format('H:i'))->toBe('10:00');
    expect($a->slot_interval_minutes)->toBe(20);
});
