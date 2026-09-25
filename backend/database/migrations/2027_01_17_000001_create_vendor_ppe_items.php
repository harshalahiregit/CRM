<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor's OWN PPE list — one table per engine.
 *
 * Until now every PPE item a vendor could hand out was a central Inventory
 * product. A vendor that buys its own helmets had nowhere to record them, so
 * its workers showed as unequipped however well kitted they were.
 *
 * These rows are the vendor's stock, not the company's: they never touch
 * inventory_stock / inventory_movements, and quantity lives here on the row.
 * TPV and Purchase keep separate tables because the two vendor masters are
 * separate (`vendors` vs `purchase_vendors`) and neither engine reads the
 * other's tables — the same split as vendor_awards / purchase_vendor_awards.
 *
 * Both issue tables gain a nullable `vendor_ppe_item_id`. An issue now draws
 * from exactly one source: an Inventory product (`inventory_item_id`, as
 * before) or one of the vendor's own items. `inventory_item_id` was already
 * nullable on both tables, so nothing existing changes shape.
 */
return new class extends Migration
{
    /** issue table => [own-items table, owner column] */
    private const SIDES = [
        'tpv_worker_ppe_issues'      => ['tpv_vendor_ppe_items', 'vendor_id'],
        'purchase_worker_ppe_issues' => ['purchase_vendor_ppe_items', 'purchase_vendor_id'],
    ];

    public function up(): void
    {
        foreach (self::SIDES as $issueTable => [$itemsTable, $ownerColumn]) {
            if (! Schema::hasTable($itemsTable)) {
                Schema::create($itemsTable, function (Blueprint $t) use ($ownerColumn, $itemsTable) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id');
                    $t->unsignedBigInteger($ownerColumn);
                    $t->string('name', 160);
                    // helmet | gloves | safety_shoes | ... — see VendorPpeCategory.
                    $t->string('category', 40)->default('other');
                    $t->string('size', 40)->nullable();
                    $t->string('spec', 255)->nullable();
                    $t->string('unit', 20)->default('pcs');
                    $t->decimal('qty_in_stock', 12, 3)->default(0);
                    $t->string('image_path', 500)->nullable();
                    $t->boolean('is_active')->default(true);
                    $t->text('notes')->nullable();
                    $t->unsignedBigInteger('created_by')->nullable();
                    $t->timestamps();

                    $t->index(['tenant_id', $ownerColumn], $itemsTable.'_owner_idx');
                });
            }

            if (Schema::hasTable($issueTable) && ! Schema::hasColumn($issueTable, 'vendor_ppe_item_id')) {
                Schema::table($issueTable, function (Blueprint $t) use ($issueTable) {
                    $t->unsignedBigInteger('vendor_ppe_item_id')->nullable();
                    $t->index('vendor_ppe_item_id', $issueTable.'_vitem_idx');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::SIDES as $issueTable => [$itemsTable]) {
            if (Schema::hasTable($issueTable) && Schema::hasColumn($issueTable, 'vendor_ppe_item_id')) {
                Schema::table($issueTable, function (Blueprint $t) use ($issueTable) {
                    $t->dropIndex($issueTable.'_vitem_idx');
                    $t->dropColumn('vendor_ppe_item_id');
                });
            }

            Schema::dropIfExists($itemsTable);
        }
    }
};
