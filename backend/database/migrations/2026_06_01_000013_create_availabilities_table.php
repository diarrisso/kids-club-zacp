<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('practitioner_id')->constrained()->cascadeOnDelete();
            $table->index('practitioner_id'); // PostgreSQL does not auto-index FKs
            $table->unsignedTinyInteger('day_of_week'); // 1=Mon, 7=Sun
            $table->time('start_time');
            $table->time('end_time');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
