<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-3 — the catalog's "preferred supplier" must reference the Purchase-owned
 * vendor master. Adds preferred_purchase_vendor_id and backfills it from the
 * legacy preferred_vendor_id by mapping each shared vendor to its purchase_vendors
 * counterpart (matched on tenant + company + email; created if absent).
 * Additive: preferred_vendor_id is left in place (dropped in Phase E-6). Touches
 * only purchase_catalog_items + reads vendors/purchase_vendors.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('purchase_catalog_items')) {
            return;
        }

        if (! Schema::hasColumn('purchase_catalog_items', 'preferred_purchase_vendor_id')) {
            Schema::table('purchase_catalog_items', function (Blueprint $table) {
                $table->unsignedBigInteger('preferred_purchase_vendor_id')->nullable()->index();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_catalog_items') && Schema::hasColumn('purchase_catalog_items', 'preferred_purchase_vendor_id')) {
            Schema::table('purchase_catalog_items', fn (Blueprint $table) => $table->dropColumn('preferred_purchase_vendor_id'));
        }
    }

    private function backfill(): void
    {
        if (! Schema::hasTable('vendors')) {
            return;
        }

        $sharedIds = DB::table('purchase_catalog_items')
            ->whereNotNull('preferred_vendor_id')
            ->whereNull('preferred_purchase_vendor_id')
            ->distinct()->pluck('preferred_vendor_id');

        foreach ($sharedIds as $sid) {
            $v = DB::table('vendors')->where('id', $sid)->first();
            if (! $v) {
                continue;
            }

            $pv = DB::table('purchase_vendors')
                ->where('tenant_id', $v->tenant_id)
                ->where('company_name', $v->company_name)
                ->when($v->email, fn ($q) => $q->where('email', $v->email))
                ->value('id');

            if (! $pv) {
                $seq  = DB::table('purchase_vendors')->where('tenant_id', $v->tenant_id)->count() + 1;
                $pv = DB::table('purchase_vendors')->insertGetId([
                    'tenant_id'            => $v->tenant_id,
                    'user_id'              => $v->user_id ?? null,
                    'purchase_vendor_code' => 'PV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                    'company_name'         => $v->company_name,
                    'legal_name'           => $v->legal_name ?? null,
                    'vendor_type'          => $v->vendor_type ?? 'standard',
                    'email'                => $v->email ?? null,
                    'phone'                => $v->phone ?? null,
                    'status'               => $v->status ?? 'Draft',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }

            DB::table('purchase_catalog_items')
                ->where('preferred_vendor_id', $sid)
                ->whereNull('preferred_purchase_vendor_id')
                ->update(['preferred_purchase_vendor_id' => $pv]);
        }
    }
};
