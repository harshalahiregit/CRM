<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this employee may sign in to the attendance app.
 *
 * Staff Management owns what somebody can do inside the CRM; HR owns whether they
 * are workforce, and the app is an HR tool — so its switch belongs on the employee
 * record rather than in the permission grid.
 *
 * DEFAULTS TO FALSE, deliberately. A new column defaulting to true would hand app
 * access to every person the moment it exists, including admins and office staff
 * who never clock in. Access is granted, not assumed — the same reasoning that
 * made the login status check an allowlist.
 *
 * The backfill then switches it on for everyone who demonstrably uses the app
 * today: an employee with a sangoetrack_user_id is already clocking in on a
 * phone, and turning them off would be a change nobody asked for. New employees
 * start off and are enabled by HR.
 *
 * Nothing reads this column yet. It is written now so the toggle exists and can be
 * set before the CRM starts serving the app, rather than being introduced on the
 * day access starts depending on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->boolean('app_login_enabled')->default(false)->after('sangoetrack_synced_at');
        });

        // Everyone already linked to SangoeTrack is already using the app.
        DB::table('hr_employees')
            ->whereNotNull('sangoetrack_user_id')
            ->update(['app_login_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('app_login_enabled');
        });
    }
};
