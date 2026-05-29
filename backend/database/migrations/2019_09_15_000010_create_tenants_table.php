<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();                  // slug-based id

            // Custom (real) columns — mirrored in Tenant::getCustomColumns()
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('trialing');    // trialing|active|suspended
            $table->foreignId('plan_id')->nullable()->constrained();
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->json('data')->nullable();                 // stancl virtual-column store
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
