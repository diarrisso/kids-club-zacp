<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

beforeEach(function () {
    Event::forget(TenantCreated::class);
});

it('lets a super admin view the central dashboard', function () {
    $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null]);

    $this->actingAs($admin)
        ->get('http://central.masinga-booking.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Central/Dashboard'));
});

it('forbids a tenant owner from the central dashboard', function () {
    $tenant = Tenant::factory()->create(['id' => 'kidsclub']);
    $owner = User::factory()->create(['role' => 'tenant_owner', 'tenant_id' => $tenant->id]);

    $this->actingAs($owner)
        ->get('http://central.masinga-booking.test/dashboard')
        ->assertForbidden();
});
