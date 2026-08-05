<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External id for a leave that originated in SangoeTrack.
 *
 * Without it a re-sync has no natural key: hr_leave_applications is keyed on
 * nothing an external system can address, so every nightly run would insert a
 * fresh duplicate of the same leave. Nullable, so leaves raised inside the CRM
 * are unaffected; unique per tenant, so a re-sync updates in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('sangoetrack_leave_id')->nullable()->after('employee_id');
            $table->unique(['tenant_id', 'sangoetrack_leave_id'], 'hr_leave_apps_tenant_sangoetrack_unique');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_applications', function (Blueprint $table) {
            $table->dropUnique('hr_leave_apps_tenant_sangoetrack_unique');
            $table->dropColumn('sangoetrack_leave_id');
        });
    }
};
