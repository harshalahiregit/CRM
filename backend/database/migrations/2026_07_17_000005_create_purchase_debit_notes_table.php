<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Debit Notes — the reverse of procure-to-pay: returning goods to a
 * vendor and reclaiming their value.
 *
 * Issuing a debit note performs the inventory adjustment (reduces received_qty
 * on the linked purchase-order items) and opens a claim on the vendor, which is
 * then settled by recorded refunds.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('debit_number');
            // The PO the returned goods were received against (nullable — a debit
            // note can also be raised standalone, with no inventory effect).
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->date('debit_date')->nullable();
            $table->string('reason')->nullable();
            // Whether issuing reduces PO received_qty. False = pure financial debit.
            $table->boolean('adjust_inventory')->default(true);

            $table->string('currency', 8)->default('INR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_refunded', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);   // total - refunded (open claim)

            $table->string('status')->default('Draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'debit_number']);
        });

        Schema::create('purchase_debit_note_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_debit_note_id')->index();
            // The PO line the return draws down (nullable for standalone debits).
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(1);   // quantity returned
            $table->string('unit')->nullable();
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('tax', 5, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_debit_note_items');
        Schema::dropIfExists('purchase_debit_notes');
    }
};
