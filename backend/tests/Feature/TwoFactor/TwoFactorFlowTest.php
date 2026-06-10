<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

it('enables, confirms, and stores a confirmed second factor with a real TOTP code', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $this->actingAs($user);

    // Satisfy the confirmPassword gate by stamping a fresh confirmation in session.
    // postJson so Fortify returns a JSON 200 instead of a web redirect (302).
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/user/two-factor-authentication')->assertOk();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();

    // Compute a valid TOTP for the freshly-generated secret and confirm.
    $secret = decrypt($user->two_factor_secret);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])
        ->assertOk();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
})->skip(! class_exists(Google2FA::class), 'pragmarx/google2fa not installed');
