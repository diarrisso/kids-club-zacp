<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('practitioner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('confirmed'); // pending|confirmed|cancelled|completed|no_show

            $table->string('patient_first_name');
            $table->string('patient_last_name');
            $table->date('patient_birthdate');

            $table->string('parent_first_name');
            $table->string('parent_last_name');
            $table->string('parent_email');
            $table->string('parent_phone')->nullable();
            $table->timestamp('parent_consent_at');

            $table->text('notes_parent')->nullable();
            $table->text('notes_internal')->nullable();
            $table->uuid('cancellation_token')->unique();
            $table->timestamps();

            $table->index(['practitioner_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
