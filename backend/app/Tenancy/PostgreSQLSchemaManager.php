<?php

namespace App\Tenancy;

use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager as BaseManager;

/**
 * Schema-per-tenant manager that keeps the `public` schema in the search_path.
 *
 * Central tables (users, tenants, plans) live in `public`. When tenancy is
 * initialized, the connection's search_path is set to the tenant schema. The
 * base stancl manager sets it to ONLY the tenant schema, which would make the
 * central `users` table invisible during tenant-scoped auth. We append `public`
 * so tenant tables resolve first, central tables fall back to public.
 *
 * PostgreSQL silently ignores a non-existent schema in search_path, so this is
 * safe even before a tenant's schema has been created.
 */
class PostgreSQLSchemaManager extends BaseManager
{
    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['search_path'] = $databaseName.',public';

        return $baseConfig;
    }
}
