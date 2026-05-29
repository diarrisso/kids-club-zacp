<?php

use App\Models\Tenant;
use App\Models\User;

it('a tenant admin can navigate the full management UI after seeding', function () {
    tenancy()->end(); // leave the default test tenant

    $this->artisan('db:seed', ['--class' => 'KidsClubTenantSeeder']);

    $user = User::where('email', 'michael@kidsclub.de')->firstOrFail();
    $this->actingAs($user);

    $tenant = Tenant::findOrFail('kidsclub');
    tenancy()->initialize($tenant);

    $this->get('http://kidsclub.masinga-booking.test/behandler')
        ->assertOk()->assertInertia(fn ($p) => $p->has('practitioners', 2));

    $this->get('http://kidsclub.masinga-booking.test/leistungen')
        ->assertOk()->assertInertia(fn ($p) => $p->has('services', 3));

    $this->get('http://kidsclub.masinga-booking.test/sprechzeiten')
        ->assertOk()->assertInertia(fn ($p) => $p->has('availabilities', 5));

    tenancy()->end();
});
