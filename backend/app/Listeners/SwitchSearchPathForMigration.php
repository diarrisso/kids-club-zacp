<?php

namespace App\Listeners;

use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Events\MigratingDatabase;

/**
 * Before tenant migrations run, point the central connection's CONFIG
 * search_path at the tenant schema and reconnect.
 *
 * The migrator introspects the schema from the connection's config (not the
 * runtime SET used at request time), so it must agree with the active schema —
 * otherwise it finds public.migrations and records tenant migrations there,
 * making other tenants skip migrations they never ran.
 *
 * Reconnecting here is safe: tenant migrations never run inside a
 * RefreshDatabase transaction (TenantTestCase uses committed schemas), so there
 * is no open transaction to orphan.
 */
class SwitchSearchPathForMigration
{
    public function __construct(protected DatabaseManager $database) {}

    public function handle(MigratingDatabase $event): void
    {
        /** @var TenantWithDatabase $tenant */
        $tenant = $event->tenant;
        $schema = $tenant->database()->getName();
        $connection = config('tenancy.database.central_connection');

        config(["database.connections.{$connection}.search_path" => $schema]);
        $this->database->purge($connection);
        $this->database->reconnect($connection);
    }
}
