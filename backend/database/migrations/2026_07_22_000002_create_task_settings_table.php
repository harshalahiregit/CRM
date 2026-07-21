<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant switches for task notifications and email.
 *
 * Tasks have never sent email — only the in-app bell. Turning four new email
 * categories on for every workspace with no way to turn them off would be a
 * change nobody asked for landing in everybody's inbox, so the switches ship
 * with the feature rather than after the complaints.
 *
 * Same key/value shape as inventory_config, which lets ConfigService's pattern
 * (declared defaults, unknown keys refused) carry over unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 60);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_settings');
    }
};
