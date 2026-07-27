<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Vendor Items — a PURE MAPPING between a Purchase Vendor
 * (purchase_vendors.purchase_vendor_id) and an Inventory item
 * (inventory_products.inventory_product_id).
 *
 * It deliberately stores NO item information (no name/sku/group/price): the
 * Inventory product remains the single Item Master and is joined at read time.
 * One Inventory item may map to many Purchase Vendors and vice-versa; the unique
 * key only prevents the SAME pair being mapped twice within a tenant.
 *
 * Purchase-owned: references purchase_vendor_id only — never vendors.vendor_id,
 * never TPV, and never inventory_product_vendors (which belongs to Inventory's
 * own inventory_vendors master).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_vendor_items')) {
            return;
        }

        Schema::create('purchase_vendor_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // The two sides of the mapping.
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->unsignedBigInteger('inventory_product_id')->index();

            // Mapping metadata (relationship-level only — never item data).
            $table->date('effective_date')->nullable();
            $table->string('status', 20)->default('Active')->index();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purchase_vendor_id']);
            $table->index(['tenant_id', 'inventory_product_id']);
            // A vendor may supply many items and an item may come from many
            // vendors — only the exact same pair twice is rejected.
            $table->unique(['tenant_id', 'purchase_vendor_id', 'inventory_product_id'], 'pvi_tenant_vendor_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendor_items');
    }
};
