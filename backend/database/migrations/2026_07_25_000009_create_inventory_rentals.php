<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental inventory — stock (or an asset) let out to a customer for a period and
 * expected back. Customer identity is kept as free text on purpose: the customer
 * master is another team's module, and coupling to it would break the strict
 * module isolation this codebase keeps. A rental tracks who has it, what the
 * rate is, when it's due back, and whether it's overdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_rentals')) {
            return;
        }

        Schema::create('inventory_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('customer_name');
            $table->string('customer_contact')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('inventory_assets')->nullOnDelete();
            $table->string('item_label')->nullable();          // snapshot when neither is linked
            $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
            $table->decimal('qty', 16, 3)->default(1);
            $table->decimal('rate', 15, 2)->default(0);
            $table->string('rate_period')->default('day');     // day | week | month
            $table->decimal('deposit', 15, 2)->nullable();
            // reserved | out | returned | overdue | cancelled
            $table->string('status')->default('reserved');
            $table->date('out_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('returned_date')->nullable();
            $table->decimal('charged', 15, 2)->nullable();     // final charge on return
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_rentals');
    }
};
