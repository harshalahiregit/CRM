<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track who raised a ticket. Visibility can then include the creator — a raiser
 * must be able to see the ticket they opened, not just the assignee/managers.
 * Nullable because widget/inbound-email tickets have no authenticated creator
 * (they're matched to a person by requester_email instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('assigned_to');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
