<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — expiry reminder tracking. Records which reminder thresholds
 * (7d/3d/1d/6h) have already fired for the current access window, so each is sent
 * once. Reset whenever the window is extended. Additive, nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->json('access_reminders_sent')->nullable()->after('validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('access_reminders_sent');
        });
    }
};
