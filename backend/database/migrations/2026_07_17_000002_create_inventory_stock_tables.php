<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory OS — the stock ledger (Phase 1 foundation).
 *
 * Two tables with very different jobs:
 *   inventory_stock     — the CURRENT balance per product/warehouse/bin (mutable).
 *   inventory_movements — the append-only HISTORY of why it changed (immutable).
 *
 * Balances are never edited by hand: every change is written as a movement and
 * applied to the balance in the same transaction, so the ledger always explains
 * the number on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('warehouse_id')->index();
            // Null = held at the warehouse without a specific bin assigned yet.
            $table->unsignedBigInteger('location_id')->nullable()->index();

            $table->decimal('quantity', 15, 3)->default(0);
            // Reserved is carved out of quantity; available = quantity - reserved.
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'warehouse_id'], 'inv_stock_lookup');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->index();

            // opening | receive | issue | adjustment | transfer | damage |
            // expired | lost | scrap | return | reserve | release
            $table->string('type');
            // in | out | transfer — kept explicit so reports never re-derive it.
            $table->string('direction');
            // Always positive; `direction` carries the sign.
            $table->decimal('quantity', 15, 3);

            $table->unsignedBigInteger('from_warehouse_id')->nullable();
            $table->unsignedBigInteger('to_warehouse_id')->nullable();
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();

            // Balance after this movement — lets history render without replaying.
            $table->decimal('balance_after', 15, 3)->nullable();

            // Reserved for the batch/serial phase.
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();

            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            // Soft link to whatever caused this (GRN, dispatch, transfer…).
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();

            // Append-only: a movement is a historical fact, so there is no
            // updated_at (see the model's UPDATED_AT = null).
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['tenant_id', 'product_id', 'created_at'], 'inv_move_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stock');
    }
};
