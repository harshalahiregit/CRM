<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the inventory purchase order from a bare stock-receiving stub into a
 * proper procurement document: a description and classification (CAPEX/OPEX,
 * tags), a delivery date and a ship-to address, order-level discount and a
 * shipping fee, plus a vendor note and terms. Per-line discount lands on the
 * lines table. All nullable / zero-default so existing POs are untouched.
 *
 * Deliberately NOT added: sale-invoice / customer / person-in-charge links —
 * those are sales & procurement-module concepts and stay out of the inventory
 * module to keep ownership boundaries clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchase_orders', function (Blueprint $table) {
            $table->string('description')->nullable()->after('code');           // human title/summary of the PO
            $table->string('type', 12)->nullable()->after('source');            // capex | opex | null
            $table->string('tags')->nullable()->after('type');                  // comma-separated free tags
            $table->date('delivery_date')->nullable()->after('expected_date');  // when it should land

            // Order-level discount + shipping. discount_amount is the resolved
            // currency figure the totals use; the mode/value/type describe how
            // it was entered so the form can round-trip it.
            $table->string('discount_type', 12)->nullable()->after('total');    // before_tax | after_tax
            $table->string('discount_mode', 10)->nullable()->after('discount_type'); // percent | amount
            $table->decimal('discount_value', 16, 2)->default(0)->after('discount_mode');
            $table->decimal('discount_amount', 16, 2)->default(0)->after('discount_value');
            $table->decimal('shipping_fee', 16, 2)->default(0)->after('discount_amount');

            $table->text('vendor_note')->nullable()->after('notes');
            $table->text('terms')->nullable()->after('vendor_note');

            // Ship-to address (the delivery destination for this order).
            $table->string('ship_address')->nullable()->after('terms');
            $table->string('ship_city')->nullable()->after('ship_address');
            $table->string('ship_state')->nullable()->after('ship_city');
            $table->string('ship_zip', 20)->nullable()->after('ship_state');
            $table->string('ship_country')->nullable()->after('ship_zip');
        });

        Schema::table('inventory_purchase_order_lines', function (Blueprint $table) {
            $table->decimal('discount_pct', 6, 2)->default(0)->after('tax_rate'); // per-line % off before tax
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'type', 'tags', 'delivery_date',
                'discount_type', 'discount_mode', 'discount_value', 'discount_amount', 'shipping_fee',
                'vendor_note', 'terms',
                'ship_address', 'ship_city', 'ship_state', 'ship_zip', 'ship_country',
            ]);
        });

        Schema::table('inventory_purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('discount_pct');
        });
    }
};
