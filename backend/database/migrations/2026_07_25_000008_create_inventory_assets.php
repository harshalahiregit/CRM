<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset management — the company's own equipment and tools, as opposed to stock
 * it sells. An asset is a single tracked thing (a forklift, a laptop, a tester)
 * with a status, an optional holder, a location and a maintenance history. It may
 * optionally point at a catalogue item, but it is not stock and never touches the
 * sellable ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_assets')) {
            Schema::create('inventory_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code')->nullable();               // asset tag
                $table->string('name');
                $table->string('category')->nullable();
                $table->foreignId('product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
                $table->string('serial_no')->nullable();
                // in_service | maintenance | idle | retired | lost
                $table->string('status')->default('in_service');
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
                $table->string('location')->nullable();
                $table->date('purchase_date')->nullable();
                $table->decimal('purchase_cost', 15, 2)->nullable();
                $table->date('warranty_until')->nullable();
                $table->date('next_service_due')->nullable();
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('inventory_asset_events')) {
            Schema::create('inventory_asset_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('asset_id')->constrained('inventory_assets')->cascadeOnDelete();
                // service | repair | inspection | assigned | returned | note
                $table->string('type');
                $table->text('description')->nullable();
                $table->decimal('cost', 15, 2)->nullable();
                $table->string('vendor')->nullable();
                $table->date('next_due')->nullable();
                $table->timestamp('performed_at')->nullable();
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'asset_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_asset_events');
        Schema::dropIfExists('inventory_assets');
    }
};
