<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dead-stock action workflow. Analytics already tells you WHAT is dead; this
 * records the DECISION about it — discount it, liquidate it, transfer it
 * somewhere it might sell, or write it off — and tracks that decision to done.
 * Without this, "₹2.4L is dead" is a number that reappears in the report every
 * week with nobody owning the fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_dead_stock_actions')) {
            return;
        }

        Schema::create('inventory_dead_stock_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            // discount | liquidate | transfer | write_off | dismiss
            $table->string('action');
            // open | in_progress | done | cancelled
            $table->string('status')->default('open');
            $table->decimal('qty', 16, 3)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
            $table->decimal('discount_percent', 6, 2)->nullable();
            $table->decimal('new_price', 15, 2)->nullable();
            $table->decimal('value_snapshot', 16, 2)->nullable();   // carrying value when the decision was taken
            $table->boolean('applied')->default(false);             // did we already push the price change etc.
            $table->text('note')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dead_stock_actions');
    }
};
