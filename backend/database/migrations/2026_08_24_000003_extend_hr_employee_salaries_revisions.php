<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Salary Engine — additive extension of hr_employee_salaries.
 *
 * Adds explicit revision metadata (revision number, reason, who assigned it). The
 * existing single-active + archive-on-reassign behaviour is unchanged; these columns
 * simply capture the "why" alongside the frozen snapshot. Defaults keep existing rows
 * valid (revision_no = 1).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_employee_salaries', function (Blueprint $table) {
            $table->unsignedInteger('revision_no')->default(1)->after('effective_to');
            $table->string('reason')->nullable()->after('revision_no');
            $table->unsignedBigInteger('assigned_by')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_salaries', function (Blueprint $table) {
            $table->dropColumn(['revision_no', 'reason', 'assigned_by']);
        });
    }
};
