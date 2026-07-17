<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widget department routing (BUG-29) + helpdesk auto-assignment (REQ-06).
 *
 *  • helpdesk_widget_settings.department_id — public widget submissions route to
 *    a department the admin picks. Null keeps today's behaviour exactly: the
 *    ticket falls back to helpdesk_settings.default_department_id inside
 *    HelpdeskService::createTicket().
 *
 *  • helpdesk_settings.auto_assign_strategy — none | round_robin | least_busy |
 *    department_manager. Defaults to 'none' so every existing tenant behaves
 *    precisely as it does today until an admin opts in.
 *
 *  • helpdesk_settings.default_assignee_id — fallback owner used when a strategy
 *    resolves nobody (e.g. department_manager on a department with no managers).
 *
 *  • helpdesk_settings.last_auto_assigned_user_id — the round-robin cursor. A
 *    plain column, not an FK: it is a rotation bookmark, and if that user is
 *    later deleted the rotation simply restarts from the top of the list.
 *
 * All additive, SQLite-safe and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_widget_settings', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('tenant_id')
                  ->constrained('ticket_departments')->nullOnDelete();
        });

        Schema::table('helpdesk_settings', function (Blueprint $table) {
            $table->string('auto_assign_strategy', 30)->default('none')->after('default_department_id');
            $table->foreignId('default_assignee_id')->nullable()->after('auto_assign_strategy')
                  ->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_auto_assigned_user_id')->nullable()->after('default_assignee_id');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_widget_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('helpdesk_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_assignee_id');
            $table->dropColumn(['auto_assign_strategy', 'last_auto_assigned_user_id']);
        });
    }
};
