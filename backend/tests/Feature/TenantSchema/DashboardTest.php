<?php

use App\Models\User;
use Database\Factories\Tenant\AppointmentFactory;
use Database\Factories\Tenant\PractitionerFactory;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects a guest to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('returns today and week counts to staff', function () {
    $today = now('Europe/Berlin')->setTime(9, 0);
    AppointmentFactory::new()->count(2)->create(['starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Dashboard')
            ->where('stats.todayCount', 2)
            ->has('todayAppointments', 2)
            ->has('rooms', 5)
        );
});

it('filters today list to the linked practitioner for a medecin', function () {
    $mine = PractitionerFactory::new()->create();
    $other = PractitionerFactory::new()->create();
    $today = now('Europe/Berlin')->setTime(9, 0);
    AppointmentFactory::new()->create(['practitioner_id' => $mine->id, 'starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);
    AppointmentFactory::new()->create(['practitioner_id' => $other->id, 'starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);

    $medecin = User::factory()->create(['role' => 'medecin', 'practitioner_id' => $mine->id]);

    $this->actingAs($medecin)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('todayAppointments', 1));
});

it('shows all appointments to an unlinked medecin (graceful degradation)', function () {
    $today = now('Europe/Berlin')->setTime(9, 0);
    AppointmentFactory::new()->count(2)->create(['starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);

    $medecin = User::factory()->create(['role' => 'medecin', 'practitioner_id' => null]);

    $this->actingAs($medecin)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('todayAppointments', 2));
});
