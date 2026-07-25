<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket reminders had remind_at + is_done but no idempotency guard, so nothing
 * could safely fire them — a scheduler would re-notify the same row on every
 * pass. notified_at is that guard (same shape as task_reminders.notified_at):
 * null = still pending, stamped = already delivered, never fires again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_reminders', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('is_done');

            // The scheduler's exact lookup: pending reminders that are now due.
            $table->index(['notified_at', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_reminders', function (Blueprint $table) {
            $table->dropIndex(['notified_at', 'remind_at']);
            $table->dropColumn('notified_at');
        });
    }
};
