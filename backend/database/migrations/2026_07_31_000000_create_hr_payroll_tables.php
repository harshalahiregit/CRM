<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Phase 4 — Payroll Processing.
 *
 * hr_payroll_runs   — one monthly payroll run per tenant (unique month+year).
 * hr_payroll_records — a frozen per-employee calculation snapshot for that run.
 *
 * The record copies the salary figures at process time so a later salary/structure
 * change never alters processed payroll. Attendance is NOT stored or computed here —
 * only a reference (source/period/days) supplied by the AttendanceProvider layer;
 * no attendance tables are created (attendance stays in SangoeTrack).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedTinyInteger('payroll_month');   // 1–12
            $table->unsignedSmallInteger('payroll_year');
            $table->string('status')->default('Draft');     // Draft | Processing | Completed | Cancelled

            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('total_gross', 16, 2)->default(0);
            $table->decimal('total_deductions', 16, 2)->default(0);
            $table->decimal('total_net', 16, 2)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Cannot process the same month twice for a tenant.
            $table->unique(['tenant_id', 'payroll_year', 'payroll_month']);
        });

        Schema::create('hr_payroll_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('payroll_run_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('employee_salary_id')->nullable();

            // Frozen salary snapshot (copied from the active employee salary at process time).
            $table->decimal('annual_ctc', 14, 2)->default(0);
            $table->decimal('monthly_ctc', 14, 2)->default(0);
            $table->decimal('gross_salary', 14, 2)->default(0);
            $table->decimal('total_benefits', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_salary', 14, 2)->default(0);

            // Attendance REFERENCE only — supplied by the AttendanceProvider (SangoeTrack, future).
            $table->string('attendance_source')->nullable();
            $table->string('attendance_period')->nullable();   // e.g. "2026-07"
            $table->decimal('payable_days', 5, 1)->nullable();
            $table->decimal('absent_days', 5, 1)->nullable();
            $table->decimal('leave_days', 5, 1)->nullable();

            $table->string('status')->default('Processed');     // Draft | Processed | Finalized
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_records');
        Schema::dropIfExists('hr_payroll_runs');
    }
};
