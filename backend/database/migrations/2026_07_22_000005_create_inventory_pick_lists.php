<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pick, pack and ship (§11 / §14).
 *
 * A delivery voucher says WHAT a customer ordered. This says how it physically
 * left the building: who walked the aisles, which bin each unit came out of,
 * what went in the carton, which carrier took it and when it arrived.
 *
 * ONE table for the whole job rather than three, because picking, packing and
 * shipping are three stages of the same physical parcel — splitting them would
 * mean joining three rows back together on every screen just to answer "where
 * is this order". The status column carries the stage:
 *
 *     open → picking → picked → packed → shipped → delivered
 *
 * Stock does not move here. Shipping posts the underlying delivery voucher, so
 * the ledger keeps its single rule: posting is the only moment stock changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_pick_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30);
            // The order being fulfilled. Nullable so a pick list can also be
            // raised for an internal job with no customer document behind it.
            $table->unsignedBigInteger('voucher_id')->nullable()->index();
            $table->unsignedBigInteger('warehouse_id')->index();
            $table->string('status', 20)->default('open')->index();

            // Who is walking the aisles. Their own queue depends on this.
            $table->unsignedBigInteger('assigned_to')->nullable()->index();

            $table->timestamp('picked_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Shipment. carrier/tracking are free text so a workspace with no
            // courier account can still record "Blue Dart, 12345" by hand — the
            // integration fills the same fields when credentials exist.
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->decimal('parcel_weight', 10, 3)->nullable();
            $table->unsignedSmallInteger('parcel_count')->nullable();
            $table->string('received_by', 120)->nullable();     // who signed for it
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_pick_list_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('pick_list_id')->index();
            $table->unsignedBigInteger('product_id')->index();

            $table->decimal('required_qty', 15, 3)->default(0);
            $table->decimal('picked_qty', 15, 3)->default(0);
            // Packing is a SECOND count of the same units, by a different person
            // where possible. That is the only thing that catches a picking
            // mistake before it reaches the customer, so it is stored separately
            // rather than assumed equal to picked.
            $table->decimal('packed_qty', 15, 3)->default(0);

            // Where the picker was sent, and which batch FEFO chose.
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pick_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_pick_list_lines');
        Schema::dropIfExists('inventory_pick_lists');
    }
};
