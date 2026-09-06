<?php

use App\Support\Hr\AdvanceStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advances: money paid out BEFORE it is spent, then settled against bills.
 *
 * Not a loan. A loan is recovered from payroll in installments and lives in
 * hr_loans; an advance is handed over for a trip or a site, and comes back as
 * receipts plus whatever is left. They share no columns worth sharing.
 *
 * Bills are attachments, not a json column. SangoeTrack keeps them in
 * `bills_uploaded`, which is why they cannot be listed, counted or removed
 * individually — the shared attachments table already does all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            // Per tenant, like every other document reference in this codebase.
            $table->string('reference', 30)->nullable();

            $table->string('advance_type', 40)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('project_site', 120)->nullable();
            $table->text('purpose');

            $table->decimal('amount_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();

            $table->date('required_date')->nullable();
            $table->date('expected_settlement_date')->nullable();

            $table->enum('status', AdvanceStage::ALL)->default(AdvanceStage::PENDING);

            // Held from, so clearing a hold returns the request to the rung it was
            // on rather than to the bottom of the ladder — being held by accounts
            // must not cost the manager's approval.
            $table->string('held_from', 30)->nullable();
            $table->decimal('proposed_amount', 12, 2)->nullable();

            // Disbursement. A reference is required for everything except cash,
            // which the service enforces — an unreferenced transfer cannot be
            // reconciled against a bank statement later.
            $table->decimal('disbursed_amount', 12, 2)->nullable();
            $table->string('disbursement_mode', 20)->nullable();
            $table->string('disbursement_reference', 100)->nullable();
            $table->unsignedBigInteger('disbursed_by')->nullable();
            $table->timestamp('disbursed_at')->nullable();

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->unique(['tenant_id', 'reference']);
        });

        Schema::create('hr_advance_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('advance_id')->index();

            $table->decimal('actual_expense', 12, 2);

            // Both stored rather than derived on read: what the company owed on
            // the day it was settled must not change because somebody later
            // corrected the disbursed figure.
            $table->decimal('balance_return', 12, 2)->default(0);   // employee returns
            $table->decimal('extra_due', 12, 2)->default(0);        // company still owes

            $table->text('notes')->nullable();

            $table->enum('status', ['pending', 'accepted', 'rejected', 'on_hold'])->default('pending');
            $table->text('review_remarks')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            // No unique on advance_id. A rejected settlement is followed by
            // another one, and BOTH are kept: SangoeTrack deletes the old row
            // ("Delete old rejected settlement if re-submitting"), which destroys
            // the only evidence of what was originally claimed.
            $table->index(['tenant_id', 'status']);
            $table->index(['advance_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_advance_settlements');
        Schema::dropIfExists('hr_advances');
    }
};
