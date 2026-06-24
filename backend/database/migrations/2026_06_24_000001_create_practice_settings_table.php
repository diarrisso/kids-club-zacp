<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('reminder_enabled')->default(true);
            $table->string('reminder_channel', 20)->default('email');
            $table->unsignedSmallInteger('reminder_lead_hours')->default(24);
            $table->text('reminder_message')->default(
                'Liebe Familie, wir erinnern an den Termin im Kids Club am {Datum} um {Uhrzeit}. Bis bald!'
            );
            $table->boolean('booking_confirmation_enabled')->default(true);
            $table->boolean('notify_on_booking')->default(true);
            $table->boolean('notify_on_cancellation')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_settings');
    }
};
