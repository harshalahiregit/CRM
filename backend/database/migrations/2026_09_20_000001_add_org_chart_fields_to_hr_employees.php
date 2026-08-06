<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #29 — "Organization chart – auto create and update based on
 * employee, consultant, freelancer added in system to consider in organization
 * chart (option to consider person in org. chart while entering in system)".
 *
 * Two things the comment asks for that the table could not express:
 *
 *  - WHAT someone is. `source` already exists but records how the record was
 *    created (onboarding vs manual), not whether the person is staff, a
 *    consultant or a freelancer. Overloading it would lose one meaning or the
 *    other.
 *  - WHETHER to chart them — the comment's explicit "option to consider person in
 *    org. chart while entering in system".
 *
 * The reporting edge itself is NOT added here: `reporting_manager_id` already
 * exists and is populated, which is why the chart can be built from data that is
 * already there rather than from a new hierarchy table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_employees', 'worker_type')) {
                // Defaults to 'employee' so every existing row keeps its current
                // meaning — nobody silently becomes a consultant.
                $table->string('worker_type', 24)->default('employee')->after('status');
            }
            if (! Schema::hasColumn('hr_employees', 'include_in_org_chart')) {
                // Defaults to true so the chart is populated on day one rather
                // than empty until somebody ticks 16 boxes.
                $table->boolean('include_in_org_chart')->default(true)->after('worker_type');
            }
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->index(['tenant_id', 'reporting_manager_id'], 'hr_employees_tenant_manager_index');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropIndex('hr_employees_tenant_manager_index');
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['worker_type', 'include_in_org_chart']);
        });
    }
};
