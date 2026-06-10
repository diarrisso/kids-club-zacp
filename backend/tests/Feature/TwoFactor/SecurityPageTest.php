<?php

use App\Models\User;

it('renders the security page with the two-factor enabled flag', function () {
    $user = User::factory()->create(); // enrolled

    $this->actingAs($user)
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Security')
            ->where('twoFactorEnabled', true));
});

it('reports two-factor disabled for an un-enrolled user', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Security')
            ->where('twoFactorEnabled', false));
});
