<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Temporary TPV Access System (foundation).
 *
 * Additive: the time-boxed access window and its lifecycle live on the shared
 * vendors master (per PRD §6.13). Permanent vendors keep `is_temporary = 0` and
 * are entirely unaffected. New columns only — migration-safe and reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('is_temporary')->default(false)->after('vendor_type');
            $table->timestamp('access_start_at')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->string('access_status')->nullable();          // Active | Expiring | Expired | Converted
            $table->timestamp('access_extended_at')->nullable();
            $table->unsignedBigInteger('access_extended_by')->nullable();
            $table->text('extension_reason')->nullable();
            $table->timestamp('converted_to_permanent_at')->nullable();
            $table->unsignedBigInteger('converted_by')->nullable();
            $table->unsignedBigInteger('temporary_created_by')->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable();

            $table->index('is_temporary');
            $table->index('access_status');
            $table->index('access_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['is_temporary']);
            $table->dropIndex(['access_status']);
            $table->dropIndex(['access_expires_at']);
            $table->dropColumn([
                'is_temporary', 'access_start_at', 'access_expires_at', 'access_status',
                'access_extended_at', 'access_extended_by', 'extension_reason',
                'converted_to_permanent_at', 'converted_by', 'temporary_created_by', 'validity_days',
            ]);
        });
    }
};
