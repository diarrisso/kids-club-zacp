<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partial composite: serves the status-filtered overlap query that runs
        // inside the booking lock + the calculator. status IN (...) is constant →
        // legal partial-index predicate (no now()).
        // Plain CREATE INDEX (not CONCURRENTLY): the table is tiny (single practice)
        // so the brief write-lock is negligible, and CONCURRENTLY cannot run inside
        // Laravel's transactional migration wrapper.
        DB::statement(
            'CREATE INDEX appointments_overlap_idx ON appointments '.
            '(practitioner_id, starts_at, ends_at) '.
            "WHERE status IN ('pending', 'confirmed')"
        );

        Schema::table('availabilities', function (Blueprint $t) {
            $t->index(['practitioner_id', 'day_of_week']);
            $t->dropIndex(['practitioner_id']); // redundant: left-prefix of the composite
        });

        Schema::table('availability_exceptions', function (Blueprint $t) {
            $t->index(['practitioner_id', 'starts_at', 'ends_at']);
            $t->dropIndex(['practitioner_id']); // redundant: left-prefix of the composite
            $t->dropIndex(['starts_at']);        // redundant standalone (audit)
        });
    }

    public function down(): void
    {
        Schema::table('availability_exceptions', function (Blueprint $t) {
            $t->dropIndex(['practitioner_id', 'starts_at', 'ends_at']);
            $t->index('practitioner_id');
            $t->index('starts_at');
        });

        Schema::table('availabilities', function (Blueprint $t) {
            $t->dropIndex(['practitioner_id', 'day_of_week']);
            $t->index('practitioner_id');
        });

        DB::statement('DROP INDEX IF EXISTS appointments_overlap_idx');
    }
};
