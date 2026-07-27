<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-1 — decouple Purchase from the shared Vendor Master (data layer).
 *
 * 1. Adds a nullable purchase_vendor_id to every Purchase table that currently
 *    carries vendor_id.
 * 2. Backfills the Purchase-owned vendor master (purchase_vendors) from the
 *    shared vendors that are engaged for Purchase (or referenced by a Purchase
 *    row), then maps each Purchase row's vendor_id → the new purchase_vendor_id.
 *
 * Additive and reversible: vendor_id is left in place (dropped only in a later
 * phase, after the code is fully rewired). Touches ONLY purchase_* tables +
 * goods_receipts and READS vendors; no TPV / HR / shared table is modified.
 */
return new class extends Migration {
    /** Purchase tables that reference a vendor. */
    private function tables(): array
    {
        return [
            'purchase_requests', 'purchase_orders', 'purchase_invoices', 'purchase_debit_notes',
            'purchase_rfq_vendors', 'purchase_quotations', 'purchase_contracts',
            'purchase_onboardings', 'purchase_contacts', 'purchase_documents',
            'purchase_kickoff_meetings', 'purchase_approvals', 'goods_receipts',
        ];
    }

    public function up(): void
    {
        // 1. Add the nullable, indexed purchase_vendor_id column everywhere.
        foreach ($this->tables() as $t) {
            if (! Schema::hasTable($t) || Schema::hasColumn($t, 'purchase_vendor_id')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            });
        }

        // 2. Backfill purchase_vendors + map old vendor_id → new purchase_vendor_id.
        $this->backfill();
    }

    public function down(): void
    {
        foreach ($this->tables() as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'purchase_vendor_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('purchase_vendor_id'));
            }
        }
        // The backfilled purchase_vendors rows are intentionally left in place.
    }

    private function backfill(): void
    {
        if (! Schema::hasTable('vendors')) {
            return;
        }

        // Vendor ids referenced by any Purchase row.
        $referenced = collect();
        foreach ($this->tables() as $t) {
            if (Schema::hasColumn($t, 'vendor_id')) {
                $referenced = $referenced->merge(
                    DB::table($t)->whereNotNull('vendor_id')->distinct()->pluck('vendor_id')
                );
            }
        }

        // Plus every shared vendor flagged with the 'purchase' engagement.
        $purchaseEngaged = DB::table('vendors')->get()->filter(function ($v) {
            $eng = json_decode($v->engagements ?? '[]', true) ?: [];

            return in_array('purchase', $eng, true);
        })->pluck('id');

        $vendorIds = $referenced->merge($purchaseEngaged)->filter()->unique()->values();
        if ($vendorIds->isEmpty()) {
            return;
        }

        $vendors = DB::table('vendors')->whereIn('id', $vendorIds->all())->get()->keyBy('id');

        $map = [];   // old vendors.id => new purchase_vendors.id
        foreach ($vendors as $vid => $v) {
            // Idempotent: reuse an already-migrated purchase vendor if present.
            $existing = DB::table('purchase_vendors')
                ->where('tenant_id', $v->tenant_id)
                ->where('company_name', $v->company_name)
                ->when($v->email, fn ($q) => $q->where('email', $v->email))
                ->first();

            if ($existing) {
                $map[$vid] = $existing->id;
                continue;
            }

            $seq  = DB::table('purchase_vendors')->where('tenant_id', $v->tenant_id)->count() + 1;
            $code = 'PV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

            $map[$vid] = DB::table('purchase_vendors')->insertGetId([
                'tenant_id'            => $v->tenant_id,
                'user_id'              => $v->user_id ?? null,
                'account_manager_id'   => $v->account_manager_id ?? null,
                'purchase_vendor_code' => $code,
                'company_name'         => $v->company_name,
                'legal_name'           => $v->legal_name ?? null,
                'vendor_type'          => $v->vendor_type ?? 'standard',
                'email'                => $v->email ?? null,
                'phone'                => $v->phone ?? null,
                'website'              => $v->website ?? null,
                'category'             => $v->category ?? null,
                'registration_number'  => $v->registration_number ?? null,
                'gst_number'           => $v->gst_number ?? null,
                'pan_number'           => $v->pan_number ?? null,
                'address'              => $v->address ?? null,
                'city'                 => $v->city ?? null,
                'state'                => $v->state ?? null,
                'country'              => $v->country ?? null,
                'pincode'              => $v->pincode ?? null,
                'status'               => $v->status ?? 'Draft',
                'approved_at'          => $v->approved_at ?? null,
                'approved_by'          => $v->approved_by ?? null,
                'notes'                => $v->notes ?? null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Map each Purchase row's vendor_id → purchase_vendor_id (only where unset).
        foreach ($this->tables() as $t) {
            if (! Schema::hasColumn($t, 'vendor_id')) {
                continue;
            }
            foreach ($map as $old => $new) {
                DB::table($t)->where('vendor_id', $old)->whereNull('purchase_vendor_id')
                    ->update(['purchase_vendor_id' => $new]);
            }
        }
    }
};
