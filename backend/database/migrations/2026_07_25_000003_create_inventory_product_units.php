<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternate units of measure for a product. Each row is a pack the item can be
 * counted/traded in — "Box", "Carton" — with `factor` = how many BASE units it
 * holds (a Box of 12 → factor 12). The ledger always stores base units; these
 * let people enter and read quantities in the pack that makes sense to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_product_units')) {
            return;
        }

        Schema::create('inventory_product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->string('name');                     // e.g. Box, Carton, Dozen
            $table->decimal('factor', 16, 6);           // base units per 1 of this unit
            $table->string('barcode')->nullable();      // pack-level barcode
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_product_units');
    }
};
