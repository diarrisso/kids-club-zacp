<?php

use App\Models\Tenant\AvailabilityException;
use App\Models\Tenant\Practitioner;
use App\Models\User;

it('creates a vacation exception spanning multiple days', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-15 23:59:59',
            'type' => 'vacation',
            'reason' => 'Sommerurlaub',
        ])
        ->assertRedirect();

    expect(AvailabilityException::count())->toBe(1);
});

it('rejects ends_at before starts_at', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-08-15 00:00:00',
            'ends_at' => '2026-08-01 23:59:59',
            'type' => 'vacation',
        ])
        ->assertSessionHasErrors('ends_at');
});

it('creates one exception per active practitioner on cabinet closure', function () {
    $p1 = Practitioner::factory()->create(['is_active' => true]);
    $p2 = Practitioner::factory()->create(['is_active' => true]);
    Practitioner::factory()->create(['is_active' => false]); // excluded

    $this->actingAs(User::factory()->create())
        ->post('/abwesenheiten', [
            'is_cabinet_closure' => true,
            'starts_at' => '2026-12-24 00:00:00',
            'ends_at' => '2026-12-26 23:59:59',
            'reason' => 'Weihnachten',
        ])
        ->assertRedirect('/abwesenheiten')
        ->assertSessionHasNoErrors();

    expect(AvailabilityException::count())->toBe(2);
    expect(AvailabilityException::pluck('type')->unique()->all())->toBe(['cabinet_closure']);
    expect(AvailabilityException::pluck('practitioner_id')->sort()->values()->all())
        ->toBe(collect([$p1->id, $p2->id])->sort()->values()->all());
    expect(AvailabilityException::pluck('reason')->unique()->all())->toBe(['Weihnachten']);
});

it('still creates a single exception when not a cabinet closure', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-07-01 09:00:00',
            'ends_at' => '2026-07-05 18:00:00',
            'type' => 'vacation',
            'reason' => 'Urlaub',
        ])
        ->assertRedirect('/abwesenheiten')
        ->assertSessionHasNoErrors();

    expect(AvailabilityException::count())->toBe(1);
    expect(AvailabilityException::first()->type)->toBe('vacation');
});

it('rejects type=cabinet_closure on a normal single create', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-07-01 09:00:00',
            'ends_at' => '2026-07-02 18:00:00',
            'type' => 'cabinet_closure',
            'reason' => 'Versuch',
        ])
        ->assertSessionHasErrors('type');

    expect(AvailabilityException::count())->toBe(0);
});

it('allows keeping cabinet_closure when editing an existing exception', function () {
    $p = Practitioner::factory()->create();
    $e = AvailabilityException::create([
        'practitioner_id' => $p->id, 'starts_at' => '2026-07-01 00:00:00',
        'ends_at' => '2026-07-01 23:59:59', 'type' => 'cabinet_closure', 'reason' => 'Feiertag',
    ]);

    $this->actingAs(User::factory()->create())
        ->put("/abwesenheiten/{$e->id}", [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-02 23:59:59',
            'type' => 'cabinet_closure',
            'reason' => 'Feiertag',
        ])
        ->assertRedirect('/abwesenheiten')
        ->assertSessionHasNoErrors();

    expect($e->fresh()->type)->toBe('cabinet_closure');
});
