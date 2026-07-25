<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Salary Engine — dedicated salary revision history.
 *
 * Append-only ledger: every salary (re)assignment writes one immutable row capturing
 * the before/after CTC snapshot, the reason and who did it. Never overwritten, never
 * hard-deleted — the source of truth for the Employee Profile revision history and the
 * Revision History report. Tenant-scoped, indexed by employee.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('employee_salary_id')->nullable();   // the new active salary row
            $table->unsignedBigInteger('from_structure_id')->nullable();
            $table->unsignedBigInteger('to_structure_id')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->date('effective_from');
            $table->string('reason')->nullable();

            // Before snapshot (previous active salary, if any).
            $table->decimal('previous_monthly_ctc', 14, 2)->nullable();
            $table->decimal('previous_annual_ctc', 14, 2)->nullable();
            $table->decimal('previous_net_salary', 14, 2)->nullable();

            // After snapshot (the new assignment).
            $table->decimal('new_monthly_ctc', 14, 2)->default(0);
            $table->decimal('new_annual_ctc', 14, 2)->default(0);
            $table->decimal('new_gross_salary', 14, 2)->default(0);
            $table->decimal('new_employer_contribution', 14, 2)->default(0);
            $table->decimal('new_total_deduction', 14, 2)->default(0);
            $table->decimal('new_net_salary', 14, 2)->default(0);

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'revision_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_revisions');
    }
};
