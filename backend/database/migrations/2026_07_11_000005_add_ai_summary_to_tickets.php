<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk Phase 6 — cache a short AI-generated summary on the ticket so it is
 * not regenerated on every page load. Regenerated only on demand (Refresh button).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('description');
            $table->timestamp('ai_summary_at')->nullable()->after('ai_summary');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_summary_at']);
        });
    }
};
