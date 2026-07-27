<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-6 — final cleanup. The Purchase module now keys entirely on
 * purchase_vendor_id → purchase_vendors; the legacy shared-Vendor FKs retained
 * during migration are no longer read by any code and are dropped here (with
 * their indexes). The two integrity uniques are recreated on the Purchase FK.
 * Touches only Purchase tables (+ goods_receipts); no TPV/HR/shared table.
 */
return new class extends Migration {
    /** table => legacy column to drop. */
    private function targets(): array
    {
        return [
            'purchase_requests'         => 'vendor_id',
            'purchase_orders'           => 'vendor_id',
            'purchase_invoices'         => 'vendor_id',
            'purchase_debit_notes'      => 'vendor_id',
            'purchase_rfq_vendors'      => 'vendor_id',
            'purchase_quotations'       => 'vendor_id',
            'purchase_contracts'        => 'vendor_id',
            'purchase_onboardings'      => 'vendor_id',
            'purchase_contacts'         => 'vendor_id',
            'purchase_documents'        => 'vendor_id',
            'purchase_kickoff_meetings' => 'vendor_id',
            'purchase_approvals'        => 'vendor_id',
            'goods_receipts'            => 'vendor_id',
            'purchase_catalog_items'    => 'preferred_vendor_id',
        ];
    }

    /** Integrity uniques to recreate on the Purchase FK (skipped if data duplicates). */
    private function uniques(): array
    {
        return [
            'purchase_rfq_vendors' => ['purchase_rfq_id', 'purchase_vendor_id'],
            'purchase_onboardings' => ['tenant_id', 'purchase_vendor_id'],
        ];
    }

    public function up(): void
    {
        foreach ($this->targets() as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Drop every index that references the LEGACY column (by name),
            // without touching the Purchase-owned equivalent's indexes.
            $purchaseEquiv = $column === 'preferred_vendor_id' ? 'preferred_purchase_vendor_id' : 'purchase_vendor_id';
            $indexes = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name LIKE ? AND name NOT LIKE ?",
                [$table, '%'.$column.'%', '%'.$purchaseEquiv.'%'],
            );
            foreach ($indexes as $idx) {
                DB::statement('DROP INDEX IF EXISTS "'.$idx->name.'"');
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
        }

        // Recreate integrity uniques on the Purchase FK (guarded against dup data).
        foreach ($this->uniques() as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $hasDup = DB::table($table)->select($cols)->groupBy($cols)->havingRaw('COUNT(*) > 1')->exists();
            if (! $hasDup) {
                try { Schema::table($table, fn (Blueprint $t) => $t->unique($cols)); } catch (\Throwable $e) { /* index may already exist */ }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->targets() as $table => $column) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable());
            }
        }
    }
};
