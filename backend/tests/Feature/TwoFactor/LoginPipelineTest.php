<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('logs in with valid credentials through the default pipeline', function () {
    // Un-enrolled user: the password step completes a full session because the
    // 2FA-enforcement middleware is not part of this task yet. (An enrolled user
    // would instead be redirected to the two-factor challenge — see the test below.)
    $user = User::factory()->withoutTwoFactor()->create([
        'password' => Hash::make('correct-horse-12!XY'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-12!XY',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('redirects an enrolled user to the two-factor challenge on valid credentials', function () {
    // The default factory user has two_factor_confirmed_at set. Valid credentials
    // must NOT complete a session; Fortify's RedirectIfTwoFactorAuthenticatable
    // sends them to the challenge instead. This is the behaviour the custom
    // authenticateUsing resolver was sidestepping.
    $user = User::factory()->create([
        'password' => Hash::make('correct-horse-12!XY'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-12!XY',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertRedirect('/login');

    $this->assertGuest();
});
