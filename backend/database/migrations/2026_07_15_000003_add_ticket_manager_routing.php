<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket-manager routing: who is told when a ticket is raised.
 *
 *  • helpdesk_settings.ticket_manager_ids — the tenant's ticket managers; they
 *    see every new ticket regardless of department.
 *  • ticket_departments.manager_ids       — people who additionally own tickets
 *    raised against that specific department.
 *
 * Both are JSON id arrays rather than pivots: they are short, read as a whole,
 * and only ever edited from the settings screen. When neither is configured the
 * service falls back to admins, so routing keeps working out of the box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_settings', function (Blueprint $table) {
            $table->json('ticket_manager_ids')->nullable()->after('default_department_id');
        });

        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->json('manager_ids')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_settings', function (Blueprint $table) {
            $table->dropColumn('ticket_manager_ids');
        });

        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->dropColumn('manager_ids');
        });
    }
};
