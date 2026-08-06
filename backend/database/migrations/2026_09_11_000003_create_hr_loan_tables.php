<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Loan & Salary Advance.
 *
 * A SALARY ADVANCE IS A LOAN. It is a loan type with `is_advance` set: single
 * instalment, no interest by default. Modelling it as a second parallel module
 * would duplicate the schedule, the approval workflow and the payroll deduction —
 * three places to fix every future bug. The UI still presents the two separately.
 *
 * The instalment schedule is generated once at approval and FROZEN. Recomputing it
 * on read would silently rewrite an employee's agreed EMI when a rate is edited.
 * Each instalment carries its own period, so payroll simply asks "what is due this
 * month?" rather than deriving dates.
 *
 * `hr_payroll_records.loan_deduction` is additive and defaults to 0, so records
 * written before this migration keep their meaning and a tenant with no loans sees
 * no change anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_loan_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            // A salary advance is a loan type, not a separate entity.
            $table->boolean('is_advance')->default(false);
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('max_tenure_months')->nullable();
            // Annual %, applied on a reducing balance. Null/0 = interest-free.
            $table->decimal('interest_rate', 6, 3)->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_loan_type_name_unique');
        });

        Schema::create('hr_employee_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('loan_type_id')->index();
            $table->string('loan_number', 40)->nullable();

            $table->decimal('principal', 14, 2);
            $table->decimal('interest_rate', 6, 3)->default(0);
            $table->unsignedSmallInteger('tenure_months')->default(1);
            $table->decimal('emi', 14, 2)->default(0);
            $table->decimal('total_payable', 14, 2)->default(0);   // principal + interest
            $table->decimal('total_repaid', 14, 2)->default(0);
            $table->decimal('outstanding', 14, 2)->default(0);

            // First payroll period the deduction applies to, "YYYY-MM". Stored
            // rather than derived so a disbursement late in a month can still start
            // deducting the following month without a rule buried in code.
            $table->string('start_period', 7)->nullable();
            $table->date('disbursed_on')->nullable();

            $table->string('status', 20)->default('Draft');
            $table->text('purpose')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'hr_loan_status_idx');
            $table->index(['tenant_id', 'employee_id', 'status'], 'hr_loan_emp_idx');
        });

        Schema::create('hr_loan_installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedSmallInteger('sequence');
            $table->string('period', 7);                    // "2026-04"
            $table->decimal('amount', 14, 2);
            $table->decimal('principal_component', 14, 2)->default(0);
            $table->decimal('interest_component', 14, 2)->default(0);
            $table->decimal('balance_after', 14, 2)->default(0);

            // Pending | Deducted | Waived | Skipped
            $table->string('status', 20)->default('Pending');
            // The payroll record that actually collected it — the audit link that
            // makes "was this deducted?" answerable without guessing from dates.
            $table->unsignedBigInteger('payroll_record_id')->nullable();
            $table->decimal('deducted_amount', 14, 2)->nullable();
            $table->date('deducted_on')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'sequence'], 'hr_loan_inst_unique');
            $table->index(['tenant_id', 'period', 'status'], 'hr_loan_inst_period_idx');
        });

        // Payroll integration — additive, default 0.
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_payroll_records', 'loan_deduction')) {
                $table->decimal('loan_deduction', 14, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_loan_installments');
        Schema::dropIfExists('hr_employee_loans');
        Schema::dropIfExists('hr_loan_types');
    }
};
