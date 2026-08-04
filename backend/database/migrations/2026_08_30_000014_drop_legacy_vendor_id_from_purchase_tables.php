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
 *
 * Driver-portable: index and foreign-key discovery goes through Schema's own
 * introspection rather than sqlite_master, so this runs on SQLite and MySQL
 * alike. MySQL also refuses to drop a column while a foreign key still
 * references it, so constraints come off before the column does.
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

            // MySQL will not drop a column that a foreign key still references.
            $this->dropForeignKeysOn($table, $column);

            // Then any index that covers the legacy column. Matching on the
            // index's actual columns (not its name) means an index on the
            // Purchase-owned equivalent is never caught by accident.
            $this->dropIndexesOn($table, $column);

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
        }

        // Recreate integrity uniques on the Purchase FK (guarded against dup data).
        foreach ($this->uniques() as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $c) {
                if (! Schema::hasColumn($table, $c)) {
                    continue 2;
                }
            }
            $hasDup = DB::table($table)->select($cols)->groupBy($cols)->havingRaw('COUNT(*) > 1')->exists();
            if (! $hasDup) {
                try { Schema::table($table, fn (Blueprint $t) => $t->unique($cols)); } catch (\Throwable $e) { /* index may already exist */ }
            }
        }
    }

    /** Drop every foreign key on $table whose local columns include $column. */
    private function dropForeignKeysOn(string $table, string $column): void
    {
        try {
            $keys = Schema::getForeignKeys($table);
        } catch (\Throwable $e) {
            return;   // driver cannot introspect FKs — nothing to unhook
        }

        foreach ($keys as $fk) {
            $cols = array_map('strtolower', $fk['columns'] ?? []);
            if (! in_array(strtolower($column), $cols, true)) {
                continue;
            }
            $name = $fk['name'] ?? null;
            if (! $name) {
                continue;
            }
            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
            } catch (\Throwable $e) { /* already gone */ }
        }
    }

    /** Drop every non-primary index on $table that covers $column. */
    private function dropIndexesOn(string $table, string $column): void
    {
        try {
            $indexes = Schema::getIndexes($table);
        } catch (\Throwable $e) {
            return;
        }

        foreach ($indexes as $idx) {
            if (! empty($idx['primary'])) {
                continue;   // never touch the primary key
            }
            $cols = array_map('strtolower', $idx['columns'] ?? []);
            if (! in_array(strtolower($column), $cols, true)) {
                continue;
            }
            $name = $idx['name'] ?? null;
            if (! $name) {
                continue;
            }
            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            } catch (\Throwable $e) { /* already gone */ }
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
