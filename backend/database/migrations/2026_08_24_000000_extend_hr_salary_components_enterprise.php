<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Salary Engine — additive extension of hr_salary_components.
 *
 * Adds Formula/Manual support and the statutory flags (taxable, pf/esic applicable)
 * plus an explicit ordering `sequence` and audit columns. All columns are nullable
 * or defaulted, so existing components and the committed Payroll module keep working
 * unchanged. No column is renamed or dropped. `type` and `calculation_type` stay
 * plain strings — the new values ('Employer', 'Formula', 'Manual') are accepted at
 * the application layer, requiring no schema change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_salary_components', function (Blueprint $table) {
            $table->text('formula')->nullable()->after('based_on');        // for calculation_type = Formula
            $table->boolean('taxable')->default(true)->after('formula');
            $table->boolean('pf_applicable')->default(false)->after('taxable');
            $table->boolean('esic_applicable')->default(false)->after('pf_applicable');
            $table->integer('sequence')->default(0)->after('esic_applicable');
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_components', function (Blueprint $table) {
            $table->dropColumn(['formula', 'taxable', 'pf_applicable', 'esic_applicable', 'sequence', 'created_by', 'updated_by']);
        });
    }
};
