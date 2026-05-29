<?php

namespace App\Tenancy;

use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Schema-per-tenant bootstrapper.
 *
 * Unlike stancl's DatabaseTenancyBootstrapper (which purges and reconnects the
 * connection — fit for database-per-tenant), this simply switches the active
 * connection's search_path to the tenant schema, keeping `public` as fallback
 * for central tables (users, tenants, plans).
 *
 * Benefits:
 *  - No connection churn (no purge/reconnect per request) → faster.
 *  - Transaction-safe: a `SET search_path` inside a transaction is rolled back
 *    with it, so it never orphans RefreshDatabase's test transaction.
 */
class SearchPathBootstrapper implements TenancyBootstrapper
{
    public function __construct(protected DatabaseManager $database) {}

    public function bootstrap(Tenant $tenant): void
    {
        /** @var TenantWithDatabase $tenant */
        $schema = $tenant->database()->getName(); // e.g. tenant_kidsclub

        $this->database->connection()->statement(
            sprintf('SET search_path TO "%s", public', $schema)
        );
    }

    public function revert(): void
    {
        $this->database->connection()->statement('SET search_path TO public');
    }
}
