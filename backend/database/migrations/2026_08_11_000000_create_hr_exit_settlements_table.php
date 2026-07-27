<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit / Separation Management — Phase 5 (Full & Final Settlement).
 *
 * One tenant-scoped table. A settlement belongs to an exit whose clearance is
 * Completed; generation reads existing Payroll / Employee Salary / Leave data and
 * freezes an immutable snapshot into `components` (JSON) — payroll is never
 * modified or recomputed. Denormalised net columns support the queue KPIs and the
 * Settlement Month filter. The audit log provides the approval/settlement history.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_exit_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('exit_request_id');   // hr_exit_requests
            $table->unsignedBigInteger('clearance_id')->nullable(); // hr_exit_clearances
            $table->unsignedBigInteger('employee_id')->index(); // hr_employees

            $table->string('status')->default('Pending')->index(); // Pending | Generated | Reviewed | Approved | Settled
            $table->string('settlement_month')->nullable()->index(); // YYYY-MM (from last working date)

            $table->json('components')->nullable();           // frozen snapshot (all line items + context)
            $table->decimal('gross_earnings', 14, 2)->nullable();
            $table->decimal('total_recoveries', 14, 2)->nullable();
            $table->decimal('net_settlement', 14, 2)->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('settled_by')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'exit_request_id']); // one settlement per exit
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_settlements');
    }
};
