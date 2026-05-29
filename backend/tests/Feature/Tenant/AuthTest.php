<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

beforeEach(function () {
    // Auth scoping is enforced on the central `users` table; we don't need real
    // tenant schemas, so skip schema creation (avoids RefreshDatabase deadlock).
    Event::forget(TenantCreated::class);
});

it('a tenant owner can log into their tenant', function () {
    $tenant = Tenant::factory()->create(['id' => 'kidsclub']);
    $tenant->domains()->create(['domain' => 'kidsclub.masinga-booking.test', 'is_primary' => true]);

    User::factory()->create([
        'email' => 'michael@kidsclub.de',
        'password' => bcrypt('secret123'),
        'role' => 'tenant_owner',
        'tenant_id' => $tenant->id,
    ]);

    $response = $this->post('http://kidsclub.masinga-booking.test/login', [
        'email' => 'michael@kidsclub.de',
        'password' => 'secret123',
    ]);

    $response->assertRedirect('/dashboard');
});

it('a user from another tenant cannot log in', function () {
    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);

    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    User::factory()->create([
        'email' => 'a@a.de',
        'password' => bcrypt('x'),
        'role' => 'tenant_owner',
        'tenant_id' => 'cabinet-a',
    ]);

    // Try to log in with cabinet-a credentials on cabinet-b's domain.
    $response = $this->post('http://cabinet-b.masinga-booking.test/login', [
        'email' => 'a@a.de',
        'password' => 'x',
    ]);

    $response->assertSessionHasErrors('email');
});
