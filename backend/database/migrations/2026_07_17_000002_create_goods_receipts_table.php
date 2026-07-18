<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods Receipt Notes (GRN) — records physical receipt of goods against an
 * issued Purchase Order. Confirming a GRN adds its accepted quantities to the
 * PO items' received_qty and advances the PO status.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('grn_number');
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('received_by')->nullable()->index();

            $table->date('received_date')->nullable();
            $table->string('delivery_note_ref')->nullable();   // vendor's challan/DC number
            $table->string('status')->default('Draft')->index(); // Draft | Confirmed | Cancelled
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'grn_number']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('goods_receipt_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->index();
            $table->string('description');
            $table->decimal('ordered_qty', 15, 2)->default(0);
            $table->decimal('accepted_qty', 15, 2)->default(0);
            $table->decimal('rejected_qty', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
