<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor-Managed Inventory. A VMI agreement hands responsibility for keeping a
 * set of items stocked to a vendor: each item has a min and a max level the
 * vendor is expected to hold it between. When on-hand drops below min, the system
 * proposes a replenishment up to max — and can turn that straight into a draft PO
 * on that vendor. It reuses the vendor master and the purchase-order engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_vmi_agreements')) {
            Schema::create('inventory_vmi_agreements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('vendor_id')->constrained('inventory_vendors')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
                $table->string('name')->nullable();
                $table->string('status')->default('active');       // active | paused
                $table->string('review_frequency')->nullable();    // weekly | fortnightly | monthly
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('inventory_vmi_items')) {
            Schema::create('inventory_vmi_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('agreement_id')->constrained('inventory_vmi_agreements')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
                $table->decimal('min_level', 16, 3)->default(0);
                $table->decimal('max_level', 16, 3)->default(0);
                $table->timestamps();
                $table->unique(['agreement_id', 'product_id']);
                $table->index(['tenant_id', 'agreement_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_vmi_items');
        Schema::dropIfExists('inventory_vmi_agreements');
    }
};
