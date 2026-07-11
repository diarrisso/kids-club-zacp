<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Atomic backstop for the waitlist dedup: a partial unique index so two
     * concurrent identical POSTs (same phone + requested service, still pending)
     * can never both insert. COALESCE(service_id, 0) makes the no-service case
     * (service_id NULL) dedup too — a plain unique index would treat NULLs as
     * distinct and let duplicates through.
     */
    public function up(): void
    {
        // Collapse any pre-existing duplicate pending rows (keep the earliest) so
        // the unique index can be built. Postgres is the real target; other drivers
        // still get the index below (SQLite supports partial/expression indexes).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DELETE FROM waitlist_entries a
                USING waitlist_entries b
                WHERE a.status = 'pending' AND b.status = 'pending'
                  AND a.parent_phone = b.parent_phone
                  AND COALESCE(a.service_id, 0) = COALESCE(b.service_id, 0)
                  AND (a.created_at > b.created_at
                       OR (a.created_at = b.created_at AND a.id > b.id))
            SQL);
        }

        DB::statement(
            'CREATE UNIQUE INDEX waitlist_pending_identity_unique '.
            'ON waitlist_entries (parent_phone, (COALESCE(service_id, 0))) '.
            "WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS waitlist_pending_identity_unique');
    }
};
