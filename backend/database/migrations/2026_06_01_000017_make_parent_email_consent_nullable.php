<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual (phone) bookings may have no email and no explicit consent
        // record. Laravel 13 changes columns natively (no doctrine/dbal).
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('parent_email')->nullable()->change();
            $table->timestamp('parent_consent_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('parent_email')->nullable(false)->change();
            $table->timestamp('parent_consent_at')->nullable(false)->change();
        });
    }
};
