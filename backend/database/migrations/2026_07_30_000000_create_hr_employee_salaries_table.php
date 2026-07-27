<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Phase 3 — Employee Salary Assignment.
 *
 * Maps an employee to a Salary Structure and stores a FROZEN SNAPSHOT of the
 * computed figures at assignment time. This is deliberate: a later edit to the
 * structure must never rewrite an employee's salary history. Only one row per
 * employee is `active` at a time; revisions archive the previous row (status
 * inactive + effective_to set) — never a hard delete.
 *
 * Tenant-scoped, `hr_*` naming. No payroll processing/payslips here.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('salary_structure_id')->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Frozen snapshot (monthly figures + annual CTC) — never recomputed from the structure.
            $table->decimal('annual_ctc', 14, 2)->default(0);
            $table->decimal('monthly_ctc', 14, 2)->default(0);
            $table->decimal('gross_salary', 14, 2)->default(0);
            $table->decimal('total_benefits', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_salary', 14, 2)->default(0);

            $table->string('status')->default('active');   // active | inactive
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_salaries');
    }
};
