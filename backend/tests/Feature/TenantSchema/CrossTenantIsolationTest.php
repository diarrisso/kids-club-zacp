<?php

use App\Models\Tenant;
use App\Models\Tenant\Practitioner;
use App\Models\User;

/*
 * CRITICAL DSGVO guarantee: one practice can never see another's patient/staff
 * data. If either test fails, the multi-tenant isolation is broken — STOP.
 *
 * These tests manage their own tenants, so we leave the TenantTestCase default
 * tenant context (set up in setUp) before switching.
 */

it('a practitioner created in tenant A is not visible from tenant B', function () {
    tenancy()->end(); // leave the default test tenant

    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);

    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    tenancy()->initialize($tenantA);
    Practitioner::create([
        'first_name' => 'Anna', 'last_name' => 'A_Specific',
        'color' => '#000000', 'is_active' => true,
    ]);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(Practitioner::count())->toBe(0)
        ->and(Practitioner::where('last_name', 'A_Specific')->exists())->toBeFalse();
    tenancy()->end();
});

it('http requests are scoped to the resolved tenant', function () {
    tenancy()->end();

    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);

    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    $userA = User::factory()->create(['tenant_id' => 'cabinet-a', 'role' => 'tenant_owner']);

    tenancy()->initialize($tenantA);
    Practitioner::factory()->count(2)->create();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    Practitioner::factory()->count(5)->create();
    tenancy()->end();

    $this->actingAs($userA)
        ->get('http://cabinet-a.masinga-booking.test/behandler')
        ->assertInertia(fn ($page) => $page->has('practitioners', 2));
});
