<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers with a real in-transit state (§15).
 *
 * Until now an internal transfer moved stock out of one warehouse and into
 * another in the same instant. That is true when the two warehouses are two
 * racks in one building. It is a lie when a lorry takes three days, and the lie
 * has a cost: for those three days the destination's figures say goods are on a
 * shelf nobody can pick from, and if two cartons fall off the back, nobody finds
 * out until somebody eventually counts.
 *
 * So a travelling consignment gets its own state:
 *
 *     draft → in_transit → received → closed
 *
 * DISPATCH moves stock out of the source and into the tenant's TRANSIT
 * warehouse. That is a deliberate choice over inventing an "in flight" flag:
 * transit stock stays real, countable and visible in the ordinary ledger, the
 * balance can never go negative, and "where is my stock?" answers with a place.
 *
 * RECEIVING moves what actually arrived from transit into the destination.
 * Whatever is left behind stays in transit — it does not evaporate — until
 * somebody either receives it later or deliberately writes it off as a transit
 * loss. A shortfall that quietly disappeared would be the whole problem again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30);
            // The internal delivery note behind it. Nullable so a consignment can
            // also be raised directly, without paperwork first.
            $table->unsignedBigInteger('voucher_id')->nullable()->index();

            $table->unsignedBigInteger('from_warehouse_id')->index();
            $table->unsignedBigInteger('to_warehouse_id')->index();
            $table->string('status', 20)->default('draft')->index();

            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedBigInteger('dispatched_by')->nullable();
            // What the receiving site was told to expect. Drives the overdue list.
            $table->date('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Who is carrying it. Free text for the same reason as a parcel's
            // courier: a workspace with no integration still has a lorry.
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->string('vehicle_no', 40)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->string('driver_phone', 30)->nullable();

            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('transfer_id')->index();
            $table->unsignedBigInteger('product_id')->index();

            $table->decimal('dispatched_qty', 15, 3)->default(0);
            // Null until somebody at the far end actually looks in the lorry —
            // the same distinction the count sheet makes between "none" and
            // "nobody has been yet".
            $table->decimal('received_qty', 15, 3)->nullable();
            // Written off deliberately, never inferred. Dispatched − received −
            // lost is what is still genuinely out there on the road.
            $table->decimal('lost_qty', 15, 3)->default(0);

            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->string('status', 20)->default('in_transit')->index();  // in_transit|received|short|written_off
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
    }
};
