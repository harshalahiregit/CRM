<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase catalog (item master): a tenant's standardized purchasable items,
 * so PR/RFQ/PO lines pick a defined SKU instead of free text.
 *
 * The catalog is a reference source. Each line-item table gets a NULLABLE
 * catalog_item_id soft link — free-text lines keep working unchanged, and a
 * picked item's values are snapshotted into the line (the link is for
 * traceability, not live lookup), so re-pricing the master never disturbs a
 * historic line.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('sku');
            $table->string('name');
            $table->string('category')->nullable()->index();     // family / group
            $table->text('description')->nullable();
            $table->string('uom')->nullable();                   // default purchase unit
            $table->decimal('default_rate', 15, 2)->default(0);  // indicative cost
            $table->decimal('default_tax', 5, 2)->default(0);    // percentage
            $table->string('hsn_code')->nullable();              // GST classification
            $table->unsignedBigInteger('preferred_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('status')->default('Draft')->index();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'status']);
        });

        // Soft link on every procurement line table. Nullable — the catalog is
        // opt-in and free text stays valid.
        foreach ([
            'purchase_request_items',
            'purchase_order_items',
            'purchase_rfq_items',
            'purchase_quotation_items',
            'purchase_contract_items',
        ] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('catalog_item_id')->nullable()->after('tenant_id')->index();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'purchase_request_items',
            'purchase_order_items',
            'purchase_rfq_items',
            'purchase_quotation_items',
            'purchase_contract_items',
        ] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('catalog_item_id');
            });
        }
        Schema::dropIfExists('purchase_catalog_items');
    }
};
