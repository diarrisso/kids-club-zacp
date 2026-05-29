<?php

namespace App\Tenancy;

use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Request-time schema switching via `SET search_path` on the live connection.
 *
 * We deliberately do NOT purge/reconnect the connection (unlike stancl's
 * DatabaseTenancyBootstrapper). Reconnecting mid-request orphans an open
 * RefreshDatabase transaction and deadlocks the test suite. A runtime `SET`
 * is transaction-safe and reverts with a rollback.
 *
 * search_path = "tenant_x", public  → tenant tables resolve first, central
 * tables (users/tenants/plans, queried via the pinned central connection or
 * the public fallback) remain reachable.
 *
 * NOTE: tenant *migrations* need the migrator's schema introspection to agree
 * with the active schema; that path uses a config-based reconnect instead
 * (see SwitchSearchPathForMigration / ResetSearchPathAfterMigration listeners),
 * which is safe because migrations never run inside a RefreshDatabase
 * transaction.
 */
class SearchPathBootstrapper implements TenancyBootstrapper
{
    public function __construct(protected DatabaseManager $database) {}

    public function bootstrap(Tenant $tenant): void
    {
        /** @var TenantWithDatabase $tenant */
        $schema = $tenant->database()->getName();

        $this->database->connection()->statement(
            sprintf('SET search_path TO "%s", public', $schema)
        );
    }

    public function revert(): void
    {
        $this->database->connection()->statement('SET search_path TO public');
    }
}
