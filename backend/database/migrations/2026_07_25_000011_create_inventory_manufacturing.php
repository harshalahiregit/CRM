<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing — bills of materials and build orders.
 *
 * A BOM is a recipe: a finished-good product, the quantity it yields, and the
 * component products (with per-batch quantities) it consumes. A build order runs
 * that recipe for a target quantity; completing it posts real stock movements —
 * components are issued OUT and the finished goods are received IN — through the
 * one stock ledger, so manufacturing is fully accounted for and never bypasses
 * the "why is this 7?" audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_boms')) {
            Schema::create('inventory_boms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
                $table->string('name')->nullable();
                $table->decimal('output_qty', 16, 3)->default(1);   // finished goods this recipe yields
                $table->string('status')->default('active');        // active | archived
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('inventory_bom_lines')) {
            Schema::create('inventory_bom_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('bom_id')->constrained('inventory_boms')->cascadeOnDelete();
                $table->foreignId('component_id')->constrained('inventory_products')->cascadeOnDelete();
                $table->decimal('qty', 16, 3)->default(0);          // per output batch
                $table->string('note')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'bom_id']);
            });
        }

        if (! Schema::hasTable('inventory_build_orders')) {
            Schema::create('inventory_build_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code')->nullable();
                $table->foreignId('bom_id')->constrained('inventory_boms')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
                $table->decimal('qty', 16, 3)->default(1);          // finished goods to build
                // draft | in_progress | completed | cancelled
                $table->string('status')->default('draft');
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_build_orders');
        Schema::dropIfExists('inventory_bom_lines');
        Schema::dropIfExists('inventory_boms');
    }
};
