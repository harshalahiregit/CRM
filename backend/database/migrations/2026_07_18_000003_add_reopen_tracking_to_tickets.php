<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-09: track ticket reopens. A ticket that goes from a closed status back to
 * an open one is "reopened" — a signal that it was resolved prematurely. We keep
 * a running count and the last reopen time so the manager dashboard can surface a
 * reopen rate and a recent-reopened list. Both are stamped in SlaService::
 * onStatusChange, the single chokepoint every status transition passes through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('reopened_count')->default(0)->after('resolved_at');
            $table->timestamp('reopened_at')->nullable()->after('reopened_count');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['reopened_count', 'reopened_at']);
        });
    }
};
