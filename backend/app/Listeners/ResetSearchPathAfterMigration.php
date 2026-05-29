<?php

namespace App\Listeners;

use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Events\DatabaseMigrated;

/**
 * After tenant migrations, restore the central connection's config search_path
 * to `public` and reconnect, so subsequent central queries (and the next
 * tenant's migration switch) start from a clean state.
 */
class ResetSearchPathAfterMigration
{
    public function __construct(protected DatabaseManager $database) {}

    public function handle(DatabaseMigrated $event): void
    {
        $connection = config('tenancy.database.central_connection');

        config(["database.connections.{$connection}.search_path" => 'public']);
        $this->database->purge($connection);
        $this->database->reconnect($connection);
    }
}
