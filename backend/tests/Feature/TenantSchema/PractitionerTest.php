<?php

use App\Models\Tenant\Practitioner;

it('creates a practitioner in the tenant schema', function () {
    $p = Practitioner::create([
        'first_name' => 'Anna',
        'last_name' => 'Müller',
        'title' => 'Dr.',
        'email' => 'anna@kidsclub.de',
        'color' => '#FF6B6B',
    ]);

    expect($p->fresh()->last_name)->toBe('Müller')
        ->and($p->is_active)->toBeTrue();
});

it('lists active practitioners only via scope', function () {
    Practitioner::factory()->create(['is_active' => true]);
    Practitioner::factory()->create(['is_active' => false]);

    expect(Practitioner::active()->count())->toBe(1);
});
