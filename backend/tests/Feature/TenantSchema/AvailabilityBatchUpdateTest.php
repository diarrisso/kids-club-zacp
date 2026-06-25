<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;

use function Pest\Laravel\actingAs;

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->practitioner = Practitioner::factory()->create();
});

it('exposes name accessor as full name', function () {
    $p = Practitioner::factory()->create(['title' => 'Dr.', 'first_name' => 'Anna', 'last_name' => 'Klein']);
    expect($p->name)->toBe('Dr. Anna Klein');
});

it('rejects an open day without times and writes nothing', function () {
    $schedule = collect(range(1, 7))->map(fn ($d) => [
        'day_of_week' => $d, 'open' => $d === 1, 'start_time' => null, 'end_time' => null,
    ])->all();

    actingAs($this->user)
        ->put(route('tenant.availabilities.batch-update'), [
            'practitioner_id' => $this->practitioner->id,
            'schedule' => $schedule,
        ])
        ->assertSessionHasErrors();

    expect(Availability::where('practitioner_id', $this->practitioner->id)->count())->toBe(0);
});

it('rejects end_time before start_time', function () {
    $schedule = collect(range(1, 7))->map(fn ($d) => [
        'day_of_week' => $d, 'open' => $d === 1,
        'start_time' => $d === 1 ? '17:00' : null,
        'end_time'   => $d === 1 ? '09:00' : null,
    ])->all();

    actingAs($this->user)
        ->put(route('tenant.availabilities.batch-update'), [
            'practitioner_id' => $this->practitioner->id,
            'schedule' => $schedule,
        ])
        ->assertSessionHasErrors();
});

it('persists open days with valid times', function () {
    $schedule = collect(range(1, 7))->map(fn ($d) => [
        'day_of_week' => $d, 'open' => in_array($d, [1, 2]),
        'start_time' => in_array($d, [1, 2]) ? '09:00' : null,
        'end_time'   => in_array($d, [1, 2]) ? '17:00' : null,
    ])->all();

    actingAs($this->user)
        ->put(route('tenant.availabilities.batch-update'), [
            'practitioner_id' => $this->practitioner->id,
            'schedule' => $schedule,
        ])
        ->assertSessionHasNoErrors();

    expect(Availability::where('practitioner_id', $this->practitioner->id)->count())->toBe(2);
});
