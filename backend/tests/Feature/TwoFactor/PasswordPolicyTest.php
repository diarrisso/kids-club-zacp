<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('rejects a weak password on update', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->actingAs($user)
        ->from('/sicherheit')
        ->put('/user/password', [
            'current_password' => 'correct-horse-12!XY',
            'password' => 'password',          // too weak: < 12, no symbol/number
            'password_confirmation' => 'password',
        ])
        ->assertSessionHasErrors('password', errorBag: 'updatePassword');
});

it('accepts a strong password on update', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->actingAs($user)
        ->put('/user/password', [
            'current_password' => 'correct-horse-12!XY',
            'password' => 'Tr0ub4dour&3xtraLong!',
            'password_confirmation' => 'Tr0ub4dour&3xtraLong!',
        ])
        ->assertSessionHasNoErrors();
});
