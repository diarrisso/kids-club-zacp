<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

beforeEach(function () {
    // These are CENTRAL-only assertions (rows + relations). We don't need real
    // tenant schemas here, so drop the synchronous CreateDatabase listener that
    // would otherwise run CREATE SCHEMA inside RefreshDatabase's open transaction
    // and deadlock. The cross-tenant isolation test keeps these events on purpose.
    Event::forget(TenantCreated::class);

    $this->plan = Plan::factory()->create(['name' => 'Starter']);
});

it('creates a tenant with a primary domain', function () {
    $tenant = Tenant::create([
        'id' => 'kidsclub',
        'name' => 'Kids Club by zacp',
        'slug' => 'kidsclub',
        'status' => 'active',
        'plan_id' => $this->plan->id,
    ]);

    $tenant->domains()->create(['domain' => 'kidsclub.masinga-booking.test', 'is_primary' => true]);

    expect($tenant->fresh()->domains)->toHaveCount(1)
        ->and($tenant->primaryDomain->domain)->toBe('kidsclub.masinga-booking.test');
});

it('attaches users to tenants', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['role' => 'tenant_owner']);

    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->role)->toBe('tenant_owner');
});

it('marks super admins without a tenant', function () {
    $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null]);
    expect($admin->isSuperAdmin())->toBeTrue();
});

it('allows the same email across different tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    User::factory()->create(['email' => 'shared@example.de', 'tenant_id' => $tenantA->id]);
    $second = User::factory()->create(['email' => 'shared@example.de', 'tenant_id' => $tenantB->id]);

    expect($second->exists)->toBeTrue()
        ->and(User::where('email', 'shared@example.de')->count())->toBe(2);
});
