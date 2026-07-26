<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · Feature 3 — Enterprise Session Management.
 *
 * A row per login session, linked to the Sanctum token that backs it. Purely
 * additive metadata: Sanctum's `personal_access_tokens` remains the source of
 * truth for authentication; this tracks device/browser/IP, remember-me, activity
 * and revocation for the sessions UI, the concurrency policy and idle timeout.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();  // personal_access_tokens.id
            $table->string('device')->nullable();
            $table->string('browser')->nullable();
            $table->string('ip')->nullable();
            $table->boolean('remember_me')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
