<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor bills (old-CRM "Bills" parity). The ledger of record stays
 * `acc_voucher_lines` — a bill just wraps a posted Purchase voucher with the
 * bill-specific metadata (vendor name, bill/due dates, paid status) needed
 * for a dedicated AP register and ageing. Paying a bill posts a second
 * (Payment) voucher and flips `status`; nothing here is a second source of
 * truth for the money — only for the workflow around it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained('acc_vouchers')->cascadeOnDelete();
            $table->foreignId('vendor_ledger_id')->constrained('acc_ledgers');
            $table->string('vendor_name');
            $table->string('bill_number')->nullable();
            $table->date('bill_date');
            $table->date('due_date');
            $table->decimal('amount', 18, 2);
            $table->string('status', 10)->default('unpaid'); // unpaid|paid — overdue is derived (due_date < today)
            $table->foreignId('paid_voucher_id')->nullable()->constrained('acc_vouchers')->nullOnDelete();
            $table->date('paid_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_bills');
    }
};
