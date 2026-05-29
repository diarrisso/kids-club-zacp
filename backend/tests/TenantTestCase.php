<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Base class for tests that operate on real tenant-schema data.
 *
 * Tenant-data tests CANNOT use RefreshDatabase: PostgreSQL `CREATE SCHEMA` is
 * transactional, so a schema created inside RefreshDatabase's uncommitted
 * transaction would be invisible to the separate `tenant` connection. We
 * instead create a real, committed tenant schema per test and drop it on
 * teardown.
 *
 * We deliberately do NOT run migrate:fresh here: dropping the public tables
 * would deadlock against any RefreshDatabase test holding an open transaction
 * in the same process. We only ensure the central schema exists (idempotent
 * migrate) and clean up our own tenant rows/schema.
 */
abstract class TenantTestCase extends TestCase
{
    protected Tenant $tenant;

    protected string $tenantId = 'testtenant';

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the central schema exists (idempotent — never drops tables).
        $this->artisan('migrate', ['--force' => true]);

        $this->cleanupTenant();

        // Creating the tenant fires CreateDatabase + MigrateDatabase: a real
        // tenant_<id> schema with its own migrated tables (committed).
        $this->tenant = Tenant::factory()->create(['id' => $this->tenantId]);
        $this->tenant->domains()->create([
            'domain' => "{$this->tenantId}.masinga-booking.test",
            'is_primary' => true,
        ]);

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        $this->cleanupTenant();

        parent::tearDown();
    }

    /** A tenant_owner user belonging to the test tenant (central row). */
    protected function makeTenantUser(): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'tenant_owner',
        ]);
    }

    /**
     * Drop ALL tenant schemas and clear central tenant/domain rows (raw, no
     * model events). Comprehensive so multi-tenant tests (e.g. cross-tenant
     * isolation) that create extra tenants leave no residue.
     */
    private function cleanupTenant(): void
    {
        $conn = DB::connection(config('tenancy.database.central_connection'));

        $schemas = $conn->select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant\\_%'"
        );
        foreach ($schemas as $s) {
            $conn->statement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $s->schema_name));
        }

        $conn->table('domains')->delete();
        $conn->table('tenants')->delete(); // cascades users with a tenant_id
    }
}
