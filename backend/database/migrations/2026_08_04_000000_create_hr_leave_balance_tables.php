<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave Management — Phase 2 (Employee Leave Balance & Allocation).
 *
 * Balances are derived from a Leave Policy's mapped types. Every change is written
 * as an immutable ledger transaction — values are never overwritten. Only one
 * ACTIVE balance per employee + leave type is allowed; that rule is enforced in
 * the service (deactivate-then-create) so historical inactive rows are preserved.
 * Reuses hr_employees / hr_leave_policies / hr_leave_types — no changes to them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_employee_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('leave_policy_id')->nullable();
            $table->unsignedBigInteger('leave_type_id')->index();

            $table->decimal('allocated', 8, 1)->default(0);
            $table->decimal('opening_balance', 8, 1)->default(0);
            $table->decimal('used', 8, 1)->default(0);
            $table->decimal('adjusted', 8, 1)->default(0);
            $table->decimal('carried_forward', 8, 1)->default(0);
            $table->decimal('available_balance', 8, 1)->default(0);

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');   // active | inactive

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Fast lookup of the one active balance per employee + leave type.
            $table->index(['tenant_id', 'employee_id', 'leave_type_id', 'status']);
        });

        Schema::create('hr_leave_balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_leave_balance_id')->index();
            $table->string('transaction_type');   // Allocation | Adjustment | Carry Forward | Opening Balance | Correction
            $table->decimal('quantity', 8, 1)->default(0);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_balance_transactions');
        Schema::dropIfExists('hr_employee_leave_balances');
    }
};
