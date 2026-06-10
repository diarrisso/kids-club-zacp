<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;

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

it('lets an un-enrolled user submit the confirm-password form (enrolment escape)', function () {
    $user = User::factory()->withoutTwoFactor()->create([
        'password' => Hash::make('correct-horse-12!XY'),
    ]);

    // Fortify's confirm-password routes ship with only ['web', 'auth:web']; the
    // enforcement middleware is wired onto the staff route group in routes/web.php.
    // To prove the allow-list actually lets the confirm-password POST through, we
    // run that exact request THROUGH EnsureTwoFactorEnrolled here. With the buggy
    // allow-list ('password.confirm' exact), routeIs() does NOT match the POST's
    // name 'password.confirm.store', so enforcement bounces it to /sicherheit and
    // the user can never confirm their password — a permanent enrolment dead-end.
    Route::post('/user/confirm-password', [
        ConfirmablePasswordController::class, 'store',
    ])->middleware(['web', 'auth:web', 'two-factor.enrolled'])->name('password.confirm.store');

    // The confirm-password POST (route name password.confirm.store) must NOT be
    // intercepted by the enforcement redirect, otherwise the user can never reach
    // the 2FA-enable endpoints that confirmPassword=true guards.
    $response = $this->actingAs($user)
        ->from('/sicherheit')
        ->post('/user/confirm-password', ['password' => 'correct-horse-12!XY']);

    // It must NOT redirect to the enforcement target; a successful confirm redirects
    // elsewhere (or 201/302 to intended). The key assertion: not bounced to /sicherheit
    // by the enforcement middleware.
    expect($response->headers->get('Location'))->not->toBe(url('/sicherheit'));
    $response->assertSessionHas('auth.password_confirmed_at');
});

it('blocks an un-enrolled user from a sensitive CRUD route', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/behandler')
        ->assertRedirect('/sicherheit');
});
