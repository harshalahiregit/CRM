<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPE issues need a lifecycle, not just an issue record.
 *
 * `tpv_worker_ppe_issues` recorded that an item went out and nothing else — no
 * return, no loss, no damage — so a worker's PPE history could never show anything
 * but "issued", and stock could never come back. These columns close that.
 *
 * Deliberately NOT a stock table: quantities live in inventory_stock and every
 * movement is written to inventory_movements. This table only records WHO holds
 * WHAT, which is the one thing Inventory does not track.
 *
 * Additive only; `inventory_item_id` already existed on this table but was unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_worker_ppe_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('tpv_worker_ppe_issues', 'status')) {
                // issued | returned | lost | damaged — the worker-facing lifecycle.
                $table->string('status', 20)->default('issued');
            }
            if (! Schema::hasColumn('tpv_worker_ppe_issues', 'returned_qty')) {
                $table->decimal('returned_qty', 12, 3)->default(0);
            }
            if (! Schema::hasColumn('tpv_worker_ppe_issues', 'returned_at')) {
                $table->timestamp('returned_at')->nullable();
            }
            if (! Schema::hasColumn('tpv_worker_ppe_issues', 'returned_by')) {
                $table->unsignedBigInteger('returned_by')->nullable();
            }
            if (! Schema::hasColumn('tpv_worker_ppe_issues', 'return_notes')) {
                $table->string('return_notes', 500)->nullable();
            }
        });

        Schema::table('tpv_worker_ppe_issues', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'ppe_issues_tenant_status_idx');
            $table->index('inventory_item_id', 'ppe_issues_product_idx');
        });
    }

    /** Index-only: SQLite cannot drop an indexed column, and losing issue history is worse. */
    public function down(): void
    {
        Schema::table('tpv_worker_ppe_issues', function (Blueprint $table) {
            $table->dropIndex('ppe_issues_tenant_status_idx');
            $table->dropIndex('ppe_issues_product_idx');
        });
    }
};
