<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments recorded against a purchase invoice. Each row adds to the invoice's
 * amount_paid; the invoice recomputes its balance and status on every change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_invoice_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->decimal('amount', 15, 2);
            $table->date('payment_date')->nullable();
            $table->string('payment_mode')->default('Bank_Transfer'); // Bank_Transfer | Cash | Cheque | UPI | Card | Other
            $table->string('reference')->nullable();                  // txn / cheque number
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_payments');
    }
};
