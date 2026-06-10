<?php

use App\Models\User;

it('exposes the fortify two-factor enable endpoint when authenticated', function () {
    $user = User::factory()->create();

    // Fortify registers POST /user/two-factor-authentication only when the feature
    // is enabled. With confirmPassword=true it first demands a password confirmation,
    // which surfaces as a 423 (locked) JSON response — proof the route exists and is
    // guarded, not a 404.
    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication')
        ->assertStatus(423);
});

it('lets the user model generate a two-factor secret (trait present)', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    expect(method_exists($user, 'twoFactorQrCodeSvg'))->toBeTrue();
});
