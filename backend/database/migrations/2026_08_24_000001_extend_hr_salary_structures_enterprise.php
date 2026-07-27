<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Salary Engine — additive extension of hr_salary_structures.
 *
 * Denormalises the computed monthly figures onto the structure (a cache for reports
 * and fast listing — the SalaryFormulaEngine remains authoritative and recomputes on
 * read). Adds an explicit `employer_contribution` total and audit columns. All new
 * columns default to 0/null, so existing structures are untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_salary_structures', function (Blueprint $table) {
            $table->decimal('monthly_ctc', 14, 2)->default(0)->after('description');
            $table->decimal('annual_ctc', 14, 2)->default(0)->after('monthly_ctc');
            $table->decimal('gross_salary', 14, 2)->default(0)->after('annual_ctc');
            $table->decimal('employer_contribution', 14, 2)->default(0)->after('gross_salary');
            $table->decimal('total_deduction', 14, 2)->default(0)->after('employer_contribution');
            $table->decimal('net_salary', 14, 2)->default(0)->after('total_deduction');
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_structures', function (Blueprint $table) {
            $table->dropColumn(['monthly_ctc', 'annual_ctc', 'gross_salary', 'employer_contribution', 'total_deduction', 'net_salary', 'created_by', 'updated_by']);
        });
    }
};
