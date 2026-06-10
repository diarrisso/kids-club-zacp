<?php

use App\Models\User;

it('redirects an un-enrolled user away from staff routes to the security page', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/sicherheit');
});

it('lets an un-enrolled user reach the security page itself (no redirect loop)', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/sicherheit')
        ->assertOk();
});

it('lets an enrolled user reach staff routes normally', function () {
    $user = User::factory()->create(); // factory default = enrolled

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

it('lets an un-enrolled user log out (escape hatch)', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest();
});
