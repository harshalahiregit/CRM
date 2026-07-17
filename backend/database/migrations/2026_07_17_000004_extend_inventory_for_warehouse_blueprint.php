<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring products + warehouses up to the Warehouse blueprint's field list (§2, §7).
 *
 * Naming stays ours (product / sku) per the team's convention; the blueprint's
 * labels map on top in the UI:
 *   sku  → "Commodity Code"      name → "Commodity Name"
 *   sku_code / sku_name are the blueprint's SEPARATE SKU fields (it lists
 *   Commodity Code and Sku Code as different columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            // Master-data links (Settings tables)
            $table->unsignedBigInteger('unit_id')->nullable()->after('base_unit');
            $table->unsignedBigInteger('type_id')->nullable()->after('category_id');
            $table->unsignedBigInteger('group_id')->nullable()->after('type_id');
            $table->unsignedBigInteger('subgroup_id')->nullable()->after('group_id');
            $table->unsignedBigInteger('tax_id')->nullable()->after('gst_rate');

            // Variation attributes → inventory_attributes rows
            $table->unsignedBigInteger('color_id')->nullable()->after('color');
            $table->unsignedBigInteger('model_id')->nullable()->after('model');
            $table->unsignedBigInteger('size_id')->nullable()->after('size');
            $table->unsignedBigInteger('style_id')->nullable()->after('variant');

            // Blueprint's separate SKU fields
            $table->string('sku_code')->nullable()->after('sku');
            $table->string('sku_name')->nullable()->after('sku_code');

            // Item hierarchy (Parent item)
            $table->unsignedBigInteger('parent_id')->nullable()->after('id');

            // Pricing: profit rate drives the sale-price auto-calc
            $table->decimal('profit_ratio', 8, 2)->nullable()->after('cost_price');
            $table->string('origin')->nullable()->after('brand');
            $table->unsignedInteger('warranty_months')->nullable()->after('shelf_life_days');

            // Workflow / ownership fields the vouchers also carry
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('route_point')->nullable();

            // "Do not update inventory numbers" — item is excluded from stock math.
            $table->boolean('without_checking_warehouse')->default(false);
        });

        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0)->after('type');
            $table->boolean('display')->default(true)->after('is_default');
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->string('zip_code')->nullable()->after('state');
            $table->string('country')->nullable()->after('zip_code');
            $table->string('note')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn([
                'unit_id', 'type_id', 'group_id', 'subgroup_id', 'tax_id',
                'color_id', 'model_id', 'size_id', 'style_id',
                'sku_code', 'sku_name', 'parent_id',
                'profit_ratio', 'origin', 'warranty_months',
                'staff_id', 'route_point', 'without_checking_warehouse',
            ]);
        });

        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn(['order', 'display', 'city', 'state', 'zip_code', 'country', 'note']);
        });
    }
};
