<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor refunds against a debit note — cash the vendor returns for goods sent
 * back. Each row draws down the debit note's open balance; the note settles when
 * the balance reaches zero.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_debit_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_debit_note_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->decimal('amount', 15, 2);
            $table->date('refund_date')->nullable();
            $table->string('refund_mode')->default('Bank_Transfer'); // reuses PurchasePaymentMode
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_debit_refunds');
    }
};
