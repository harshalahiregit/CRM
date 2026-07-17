<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory OS — voucher documents (Warehouse blueprint §3–§6).
 *
 * The blueprint lists four document types with their own tables. They share ~80%
 * of their columns and ALL of their behaviour (header + line grid → post → write
 * movements), so they live in one table keyed by `type`:
 *
 *   receipt          §3 Inventory receiving voucher   (stock IN)
 *   delivery         §4 Inventory delivery voucher    (stock OUT)
 *   internal         §5 Internal delivery note        (warehouse → warehouse)
 *   loss_adjustment  §6 Loss & adjustment             (write-off / recount)
 *
 * A voucher is only paperwork until it's POSTED. Posting is what writes rows to
 * the stock ledger, so a draft can be edited freely without ever moving a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type')->index();               // receipt | delivery | internal | loss_adjustment
            $table->string('code');                        // auto: RCV-0001, DLV-0001 …
            $table->string('status')->default('draft');    // draft | posted | cancelled

            // Blueprint keeps two dates: the accounting date and the voucher day.
            $table->date('date_c')->nullable();            // Accounting date
            $table->date('date_add')->nullable();          // Voucher Day
            $table->text('description')->nullable();       // Note

            // Where the stock lands/leaves. Lines may override per row.
            $table->unsignedBigInteger('warehouse_id')->nullable();

            // §3 receiving
            $table->string('supplier_name')->nullable();
            $table->string('supplier_code')->nullable();
            $table->string('deliver_name')->nullable();
            $table->string('invoice_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('expense_type')->nullable();     // CAPEX | OPEX
            $table->string('department')->nullable();
            $table->string('requester')->nullable();
            $table->unsignedBigInteger('buyer_id')->nullable();
            // Soft links — the PO module doesn't exist here, and Projects is
            // another module, so neither is a foreign key.
            $table->unsignedBigInteger('pr_order_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();

            // §4 delivery
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('address')->nullable();

            // §6 loss & adjustment
            $table->string('adjustment_type')->nullable();  // loss | adjustment
            $table->text('reason')->nullable();

            // Totals (recomputed from the lines on every save)
            $table->decimal('total_goods', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            // Approval / ownership layer the blueprint hangs off each voucher
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('route_point')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'type', 'code']);
            $table->index(['tenant_id', 'type', 'status']);
        });

        Schema::create('inventory_voucher_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('voucher_id')->index();
            $table->unsignedBigInteger('product_id')->index();

            // receipt / delivery / loss use warehouse_id; internal uses from→to.
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('from_warehouse_id')->nullable();
            $table->unsignedBigInteger('to_warehouse_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            // For an 'adjustment' voucher this is the COUNTED total, not a delta —
            // posting works out the difference (see VoucherService::post).
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);

            // Snapshot of on-hand when the line was typed — the blueprint shows
            // "Available quantity" next to the counted figure.
            $table->decimal('available_qty', 15, 3)->nullable();

            // §6 lines carry lot + expiry; receipts may too.
            $table->string('lot_number')->nullable();
            $table->date('expiry_date')->nullable();

            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_voucher_items');
        Schema::dropIfExists('inventory_vouchers');
    }
};
