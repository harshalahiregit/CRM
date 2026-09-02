<?php

use App\Support\Hr\ReimbursementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense claims.
 *
 * The CRM has had no reimbursement table at all — the feature lived entirely on
 * SangoeTrack. This is the native one, built with the things that were missing
 * there rather than porting them as they stand.
 *
 * WHAT IS DIFFERENT FROM SANGOETRACK:
 *
 * A hold state. There, an admin could only approve or reject, so a claim with a
 * receipt for the wrong amount had to be rejected outright and started again.
 *
 * A separate approved amount. SangoeTrack has no amount field an admin can
 * modify, so partial approval was impossible.
 *
 * No attachment column. Receipts go to the shared `attachments` table, which is
 * already polymorphic and tenant-scoped — so a claim can carry several files
 * (a bill, a payment screenshot, a boarding pass) instead of one, and a replaced
 * receipt sits beside the original rather than overwriting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_reimbursements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->date('expense_date');

            $table->decimal('amount_claimed', 12, 2);

            // Null until decided. Set to the claimed amount on a plain approval,
            // or to something else when the admin approves a different figure.
            $table->decimal('amount_approved', 12, 2)->nullable();

            $table->enum('status', ReimbursementStatus::ALL)->default(ReimbursementStatus::PENDING);

            // What the request was held FROM, so clearing a hold returns it there
            // rather than to a guess. One hold state serves every stage; the
            // origin is remembered instead of duplicating the state per stage.
            $table->string('held_from', 20)->nullable();

            // Optional, and only meaningful while on hold: the figure the admin is
            // proposing. Its presence is what turns a hold into a counter-offer and
            // what makes "Accept" appear for the employee. Most holds are a
            // question and leave this null.
            $table->decimal('proposed_amount', 12, 2)->nullable();

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_reimbursements');
    }
};
