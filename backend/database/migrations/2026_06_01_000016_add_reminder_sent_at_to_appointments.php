<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Set once when the 24h reminder is queued, so it is never sent twice.
            $table->timestamp('reminder_sent_at')->nullable()->after('parent_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
