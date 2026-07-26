<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Phase 1 — Salary Components master.
 *
 * Reusable, tenant-scoped salary components (Earnings / Deductions / Benefits)
 * that future phases (Salary Structures, Employee Salary, Payroll Processing,
 * Payslips) will compose. No processing/calculation engine here — this is the
 * definition master only. Follows the existing `hr_*` naming convention and the
 * tenant_id row-level tenancy used across the HR module.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_salary_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->string('type');              // Earning | Deduction | Benefit
            $table->string('calculation_type');  // Fixed | Percentage
            $table->decimal('amount_value', 12, 2)->nullable();   // for Fixed
            $table->decimal('percentage_value', 5, 2)->nullable(); // for Percentage
            $table->string('based_on')->nullable();               // e.g. "Basic" — what the % applies to
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Tenant-scoped uniqueness for both name and code.
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_components');
    }
};
