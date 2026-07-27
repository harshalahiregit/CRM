<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Orders — the commitment stage of procure-to-pay:
 * (Purchase Request →) Purchase Order → Goods Receipt → Invoice.
 *
 * A PO may be created blank or sourced from an approved Purchase Request.
 * Per-item received_qty is rolled up from confirmed Goods Receipts and drives
 * the PO status (Issued → Partially_Received → Received).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('po_number');
            // Source PR, when the PO was converted from one (nullable — a PO can
            // also be raised directly).
            $table->unsignedBigInteger('purchase_request_id')->nullable()->index();
            // Required at submit time and must be an Active vendor — enforced in
            // the service, not the column, so drafts can be saved incrementally.
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('title');
            $table->string('department')->nullable();
            $table->date('order_date')->nullable();
            $table->date('expected_delivery_date')->nullable();

            $table->string('currency', 8)->default('INR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->string('status')->default('Draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'po_number']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(1);          // ordered
            $table->decimal('received_qty', 15, 2)->default(0);  // rolled up from GRNs
            $table->string('unit')->nullable();
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('tax', 5, 2)->default(0);            // percentage
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
