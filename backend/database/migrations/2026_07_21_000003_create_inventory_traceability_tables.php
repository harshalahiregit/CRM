<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability layer: batches, serial numbers and stock reservations.
 *
 * The movement ledger already carried batch_no/serial_no as free text, which is
 * enough to record WHAT happened but not enough to answer "what's still on the
 * shelf from batch X, and when does it expire?". These tables give a batch and a
 * serial their own identity so quantity, expiry, quality status and ownership
 * can be tracked and recalled.
 *
 * Reservations are kept as rows rather than a single number so a commitment can
 * be attributed — who reserved it, for which customer/project, at what priority
 * — while inventory_stock.reserved_quantity stays the fast aggregate the stock
 * screens read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();

            $table->string('batch_no', 60);
            $table->string('lot_number', 60)->nullable();
            $table->string('vendor_batch_no', 60)->nullable();

            $table->date('manufactured_at')->nullable();
            $table->date('expiry_date')->nullable()->index();

            // received = what arrived; remaining = what's left, so FEFO can pick.
            $table->decimal('received_qty', 15, 3)->default(0);
            $table->decimal('remaining_qty', 15, 3)->default(0);
            $table->decimal('cost_price', 15, 2)->nullable();

            // pending → passed / failed / quarantine. Only 'passed' may be issued.
            $table->string('quality_status', 20)->default('passed');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'batch_no']);
            $table->index(['tenant_id', 'expiry_date']);
        });

        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();

            $table->string('serial_no', 80);
            // in_stock | issued | returned | scrapped
            $table->string('status', 20)->default('in_stock')->index();

            $table->date('warranty_until')->nullable();
            $table->string('customer_ref')->nullable();      // who holds it once issued
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // A serial number is unique per tenant by definition — that's the point of it.
            $table->unique(['tenant_id', 'serial_no']);
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('warehouse_id')->index();

            $table->decimal('quantity', 15, 3);

            // customer | project | sales_order | production
            $table->string('reserved_for', 20)->default('customer');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_label')->nullable();

            $table->unsignedInteger('priority')->default(5);      // 1 = highest
            // active | released | fulfilled
            $table->string('status', 20)->default('active')->index();
            $table->date('expires_at')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_batches');
    }
};
