<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Phase 5 — Payslips.
 *
 * One payslip per completed payroll record. Freezes the salary figures AND a
 * component breakdown snapshot at generation time, so the payslip never changes
 * even if salaries/structures change later. The PDF is rendered via the existing
 * dompdf + Blade pattern onto the hr_documents disk. Never hard-deleted (Cancelled).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('payroll_run_id')->index();
            $table->unsignedBigInteger('payroll_record_id');
            $table->unsignedBigInteger('employee_id')->index();

            $table->string('payslip_number');
            $table->unsignedTinyInteger('payslip_month');
            $table->unsignedSmallInteger('payslip_year');

            // Frozen salary snapshot (copied from the payroll record).
            $table->decimal('gross_salary', 14, 2)->default(0);
            $table->decimal('total_benefits', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_salary', 14, 2)->default(0);

            // Frozen component breakdown (earnings/benefits/deductions) captured at generation.
            $table->json('breakdown')->nullable();

            $table->string('pdf_path')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->string('status')->default('Generated'); // Generated | Cancelled

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One payslip per payroll record (duplicate-generation guard) + unique number.
            $table->unique(['tenant_id', 'payroll_record_id']);
            $table->unique(['tenant_id', 'payslip_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
