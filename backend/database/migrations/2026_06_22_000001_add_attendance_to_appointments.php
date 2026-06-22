<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // null = not yet recorded · 'arrived' · 'no_show'. Validation lives
            // in the Form Request (Rule::enum), not a DB CHECK — project convention.
            $table->string('attendance')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('attendance');
        });
    }
};
