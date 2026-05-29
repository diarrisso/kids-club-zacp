<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('tenant_owner')->after('email'); // super_admin|tenant_owner
            $table->string('tenant_id')->nullable()->after('role');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Email is unique PER tenant, not globally: the same person may own
            // multiple practices with the same email.
            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
        });

        // The composite unique treats NULL tenant_id rows as distinct (Postgres),
        // so it would NOT prevent duplicate super-admin emails. A partial unique
        // index keeps central (super_admin) emails unique among themselves.
        DB::statement('CREATE UNIQUE INDEX users_central_email_unique ON users (email) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_central_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['role', 'tenant_id']);
            $table->unique(['email']);
        });
    }
};
