<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Settings — the last three flags the two-tab create form needs (owner: Shivam).
 *
 *  • hide_tasks_on_main  — keep this project's tasks OUT of the global admin
 *    Tasks table (they still show inside the project). Enforced in TaskRepository.
 *  • send_created_email  — Tab 1 "Send project created email" checkbox.
 *  • contacts_notification — Tab 2 required select: all / specific / none.
 *
 * visible_tabs / customer_permissions already exist (add_pin_and_settings). These
 * complete the settings bag; like those, they're stored config the client portal
 * will honour, not columns we filter on (except hide_tasks_on_main, which is).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('hide_tasks_on_main')->default(false)->after('customer_permissions');
            $table->boolean('send_created_email')->default(false)->after('hide_tasks_on_main');
            $table->string('contacts_notification', 20)->default('all')->after('send_created_email');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['hide_tasks_on_main', 'send_created_email', 'contacts_notification']);
        });
    }
};
