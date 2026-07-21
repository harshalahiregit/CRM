<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the remaining warehouse-blueprint gaps in one pass:
 *
 *  §1  Items      — tags + per-item custom field values
 *  §9  Settings   — custom field DEFINITIONS (product/warehouse) and a
 *                   tenant config store for the settings tabs that are
 *                   configuration rather than lookup lists (inventory rules,
 *                   approvals, min/max defaults, sale-price rule).
 *  §2/§3 Vouchers — total_discount and inventory_value, the two money columns
 *                   the receiving/delivery vouchers show but had nowhere to keep.
 *
 * Custom field VALUES live as JSON on the row they belong to rather than in an
 * EAV values table: the definitions are per-tenant and few, the values are only
 * ever read with their parent, and JSON keeps a product to one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('description');
            $table->json('custom_fields')->nullable()->after('status');
        });

        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('note');
        });

        Schema::table('inventory_vouchers', function (Blueprint $table) {
            $table->decimal('total_discount', 15, 2)->default(0)->after('total_tax');
            $table->decimal('inventory_value', 15, 2)->default(0)->after('total_amount');
        });

        // Custom field definitions — what fields exist, for which entity.
        Schema::create('inventory_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('entity', 20)->default('product');   // product | warehouse
            $table->string('key', 60);                          // slug used in the JSON bag
            $table->string('label');
            $table->string('type', 20)->default('text');        // text | number | date | select | checkbox
            $table->json('options')->nullable();                // choices, for type=select
            $table->boolean('required')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity', 'key']);
        });

        // Tenant configuration — one row per setting key, value kept as JSON so a
        // setting can be a scalar, a list or an object without a schema change.
        Schema::create('inventory_config', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 60);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_config');
        Schema::dropIfExists('inventory_custom_fields');

        Schema::table('inventory_vouchers', function (Blueprint $table) {
            $table->dropColumn(['total_discount', 'inventory_value']);
        });
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn(['tags', 'custom_fields']);
        });
    }
};
