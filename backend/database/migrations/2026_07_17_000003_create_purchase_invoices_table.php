<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Invoices — the billing stage of procure-to-pay:
 * (Purchase Order → Goods Receipt →) Purchase Invoice → Payments → Paid.
 *
 * An invoice may be raised from a received PO or entered directly. Payments are
 * recorded against it and roll up into amount_paid / balance, driving the status.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('invoice_number');
            // Source PO, when billed from one (nullable — an invoice can be direct).
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('title')->nullable();
            $table->string('vendor_invoice_ref')->nullable();   // the vendor's own invoice number
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();

            $table->string('currency', 8)->default('INR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);

            $table->string('status')->default('Draft')->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'invoice_number']);
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_invoice_id')->index();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(1);
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
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
