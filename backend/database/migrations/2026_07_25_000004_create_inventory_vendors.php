<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor master for procurement. Until now a supplier was just free text on a
 * receipt; this makes vendors real records, and links them to the items they
 * supply (with the vendor's own SKU, price, MOQ, lead time and a preferred flag)
 * so reordering knows who to buy from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_vendors')) {
            Schema::create('inventory_vendors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('gstin')->nullable();
                $table->string('address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('country')->nullable();
                $table->string('payment_terms')->nullable();     // e.g. Net 30
                $table->unsignedInteger('lead_time_days')->nullable();
                $table->string('status')->default('active');      // active | inactive
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('inventory_product_vendors')) {
            Schema::create('inventory_product_vendors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
                $table->foreignId('vendor_id')->constrained('inventory_vendors')->cascadeOnDelete();
                $table->string('vendor_sku')->nullable();
                $table->decimal('price', 15, 2)->nullable();
                $table->decimal('moq', 16, 3)->nullable();        // minimum order quantity
                $table->unsignedInteger('lead_time_days')->nullable();
                $table->boolean('is_preferred')->default(false);
                $table->timestamps();

                $table->unique(['product_id', 'vendor_id']);
                $table->index(['tenant_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_product_vendors');
        Schema::dropIfExists('inventory_vendors');
    }
};
