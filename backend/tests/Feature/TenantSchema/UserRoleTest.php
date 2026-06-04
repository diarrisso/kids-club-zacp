<?php

use App\Models\User;
use App\Models\Tenant\Practitioner;
use Database\Factories\Tenant\PractitionerFactory;

it('defaults new users to the secretaire role', function () {
    $user = User::factory()->create();

    expect($user->fresh()->role)->toBe('secretaire')
        ->and($user->isSecretaire())->toBeTrue()
        ->and($user->isMedecin())->toBeFalse();
});

it('links a medecin user to a practitioner fiche', function () {
    $practitioner = PractitionerFactory::new()->create();
    $user = User::factory()->create([
        'role' => 'medecin',
        'practitioner_id' => $practitioner->id,
    ]);

    expect($user->isMedecin())->toBeTrue()
        ->and($user->practitioner)->toBeInstanceOf(Practitioner::class)
        ->and($user->practitioner->id)->toBe($practitioner->id);
});

it('leaves practitioner null for unlinked users', function () {
    expect(User::factory()->create()->practitioner)->toBeNull();
});
