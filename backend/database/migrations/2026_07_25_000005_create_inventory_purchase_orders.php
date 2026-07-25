<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders — the procurement document that turns "we're low" into "we've
 * ordered more". A PO belongs to one vendor and lists what to buy, at what price,
 * to be received where. Its lines carry a running received_qty so a PO can be
 * fulfilled by one delivery or several, and it knows when it is fully done.
 *
 * Auto-reorder writes these too: low-stock items are grouped by their preferred
 * vendor and one draft PO is raised per vendor, ready for a human to review and
 * send. Nothing is ordered without a person approving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_purchase_orders')) {
            Schema::create('inventory_purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code')->nullable();                       // PO-000001
                $table->foreignId('vendor_id')->constrained('inventory_vendors')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
                // draft | submitted | approved | sent | partial | received | cancelled
                $table->string('status')->default('draft');
                $table->string('source')->default('manual');             // manual | auto
                $table->string('currency', 8)->nullable();               // left for multi-currency; null = tenant base
                $table->date('order_date')->nullable();
                $table->date('expected_date')->nullable();
                $table->decimal('subtotal', 16, 2)->default(0);
                $table->decimal('tax_total', 16, 2)->default(0);
                $table->decimal('total', 16, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'vendor_id']);
            });
        }

        if (! Schema::hasTable('inventory_purchase_order_lines')) {
            Schema::create('inventory_purchase_order_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->constrained('inventory_purchase_orders')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
                $table->string('description')->nullable();                // snapshot of the item name
                $table->decimal('qty', 16, 3)->default(0);
                $table->decimal('received_qty', 16, 3)->default(0);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->decimal('line_total', 16, 2)->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'purchase_order_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_order_lines');
        Schema::dropIfExists('inventory_purchase_orders');
    }
};
