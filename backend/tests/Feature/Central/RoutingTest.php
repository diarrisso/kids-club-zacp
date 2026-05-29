<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

beforeEach(function () {
    // Routing assertions don't need real tenant schemas; skip schema creation
    // to avoid the RefreshDatabase/CREATE SCHEMA deadlock.
    Event::forget(TenantCreated::class);
});

it('central domain shows marketing landing', function () {
    $response = $this->get('http://central.masinga-booking.test/');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('Central/Landing'));
});

it('unknown subdomain returns 404', function () {
    $response = $this->get('http://unknown.masinga-booking.test/');

    $response->assertNotFound();
});

it('tenant domain resolves to tenant dashboard', function () {
    $tenant = Tenant::factory()->create(['id' => 'kidsclub']);
    $tenant->domains()->create(['domain' => 'kidsclub.masinga-booking.test', 'is_primary' => true]);

    $response = $this->get('http://kidsclub.masinga-booking.test/');

    $response->assertRedirect(); // redirect to /login (not authenticated yet)
});
