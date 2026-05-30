<?php

use App\Models\Tenant\Practitioner;
use App\Models\User;

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

it('lists practitioners on the index page', function () {
    Practitioner::factory()->count(3)->create();

    $this->actingAs(User::factory()->create())
        ->get('/behandler')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Practitioners/Index')
            ->has('practitioners', 3)
        );
});

it('creates a practitioner via POST', function () {
    $this->actingAs(User::factory()->create())
        ->post('/behandler', [
            'first_name' => 'Anna', 'last_name' => 'Müller', 'title' => 'Dr.',
            'email' => 'anna@kidsclub.de', 'color' => '#FF6B6B', 'is_active' => true,
        ])
        ->assertRedirect();

    expect(Practitioner::where('last_name', 'Müller')->exists())->toBeTrue();
});
