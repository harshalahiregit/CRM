<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order Returns — the goods-return document raised against a Purchase
 * Order for a Purchase Vendor. A distinct document from purchase_debit_notes
 * (which stays untouched): an order return carries its own OR-#### series and
 * its own line-level discounts.
 *
 * Money columns mirror the screen: subtotal ("Total amount"), discount_total
 * ("Discount total") and total ("Total after discount" = subtotal − discount +
 * tax). Purchase-owned throughout — purchase_vendor_id only, no shared Vendor,
 * no TPV.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_order_returns')) {
            Schema::create('purchase_order_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('or_number', 40);
                $table->unsignedBigInteger('purchase_vendor_id')->index();
                $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();

                $table->date('return_date')->nullable();
                $table->text('reason')->nullable();
                // Whether confirming this return should hand stock back to Inventory.
                $table->boolean('adjust_inventory')->default(false);

                $table->string('currency', 8)->default('INR');
                $table->decimal('subtotal', 15, 2)->default(0);        // Total amount
                $table->decimal('discount_total', 15, 2)->default(0);  // Discount total
                $table->decimal('tax_total', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);           // Total after discount

                $table->string('status', 20)->default('Draft')->index();
                $table->timestamp('issued_at')->nullable();
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'or_number']);
                $table->index(['tenant_id', 'purchase_vendor_id']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('purchase_order_return_items')) {
            Schema::create('purchase_order_return_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('purchase_order_return_id')->index();
                // The ordered line being returned, when the return came from a PO.
                $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();

                $table->string('description');
                $table->decimal('qty', 15, 3)->default(0);
                $table->string('unit', 30)->nullable();
                $table->decimal('rate', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);   // per-line discount amount
                $table->decimal('tax', 8, 2)->default(0);         // percent
                $table->decimal('amount', 15, 2)->default(0);     // net of discount, incl. tax
                $table->unsignedInteger('sort_order')->default(0);

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_return_items');
        Schema::dropIfExists('purchase_order_returns');
    }
};
