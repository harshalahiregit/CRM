<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory OS — master data (Phase 1 foundation).
 *
 * Products live in `inventory_products`, deliberately separate from the Sales
 * module's `sales_items` (a billing catalog: name/rate/tax, no stock). Inventory
 * never writes to another module's table; `sales_item_id` is a soft link that a
 * contract can resolve when billing integration lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Categories (self-nesting tree) ──────────────────────────
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // ── Warehouses / branches ───────────────────────────────────
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code')->nullable();
            // cold_storage | open_yard | godown | store_room | transit | virtual
            $table->string('type')->default('godown');
            $table->string('address')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Bin locations: warehouse → zone → rack → shelf → bin ────
        // One self-nesting table rather than five, so the hierarchy can be any
        // depth a site actually uses instead of forcing all five levels.
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('warehouse_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            // zone | rack | shelf | bin | position
            $table->string('type')->default('bin');
            $table->decimal('capacity', 15, 3)->nullable();
            $table->timestamps();
        });

        // ── Product master ──────────────────────────────────────────
        Schema::create('inventory_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('sku');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('barcode')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();

            // Classification
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('variant')->nullable();
            $table->string('size')->nullable();
            $table->string('color')->nullable();

            // Units & physical
            $table->string('base_unit')->default('pcs');
            $table->decimal('weight', 12, 3)->nullable();
            $table->decimal('volume', 12, 3)->nullable();
            $table->string('image_path')->nullable();

            // Tax
            $table->string('hsn')->nullable();
            $table->string('sac')->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();

            // Planning levels — drive low-stock/reorder alerts
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('max_stock', 15, 3)->nullable();
            $table->decimal('reorder_point', 15, 3)->default(0);
            $table->decimal('safety_stock', 15, 3)->default(0);

            // Traceability switches — the batch/serial phases read these.
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_serial')->default(false);
            $table->unsignedInteger('shelf_life_days')->nullable();

            // Costing
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->decimal('sale_price', 15, 2)->nullable();

            // Soft link to the Sales catalog (resolved via contract, never joined).
            $table->unsignedBigInteger('sales_item_id')->nullable();

            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // SKU is the human key — unique per tenant, not globally.
            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_warehouses');
        Schema::dropIfExists('inventory_categories');
    }
};
