<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave Management — Phase 3 (Leave Applications) + Phase 4 (Approval).
 *
 * One table drives the whole lifecycle: Draft → Submitted → Approved / Rejected /
 * Cancelled. Approval (Phase 4) is a state transition on this row — no separate
 * approval entity — and deducts balance through the existing balance service,
 * writing an immutable ledger transaction. Reuses hr_employees / hr_leave_types /
 * hr_leave_policies / hr_employee_leave_balances (no changes to them).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_leave_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_policy_id')->nullable();
            $table->unsignedBigInteger('employee_leave_balance_id')->nullable();

            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 1)->default(0);
            $table->boolean('half_day')->default(false);
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();

            $table->string('status')->default('Submitted')->index();  // Draft | Submitted | Approved | Rejected | Cancelled
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_applications');
    }
};
