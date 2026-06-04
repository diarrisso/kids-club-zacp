<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cosmetic role only: both roles are full "admin"; this just
            // personalizes the dashboard/sidebar. No policy depends on it.
            $table->string('role')->default('secretaire')->after('email');
            // Optional link so a medecin's dashboard can highlight "their" RDV.
            $table->foreignId('practitioner_id')->nullable()->after('role')
                ->constrained('practitioners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('practitioner_id');
            $table->dropColumn('role');
        });
    }
};
