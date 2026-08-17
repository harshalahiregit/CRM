<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional link from a shared/TPV vendor to its Purchase counterpart.
 *
 * Purchase was decoupled from the vendor master on purpose (migrations
 * ..._000009 → ..._000014): every commercial document keys to
 * purchase_vendors.purchase_vendor_id, and ..._000015 records that the Purchase
 * side "stays 100% Purchase-owned". That decision is preserved here — the column
 * goes on `vendors`, NOT back onto purchase_vendors, so nothing Purchase owns is
 * touched and no Purchase query changes.
 *
 * It exists so the TPV vendor screen can show the real commercial documents when
 * the same company is registered in both modules. Nullable and unenforced: the
 * overwhelmingly common case is a vendor that is one or the other, and that stays
 * exactly as it is today. Matching is an explicit, audited admin action rather
 * than a guess on company name — these are financial documents, and showing the
 * wrong company's invoices is worse than showing none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('purchase_vendor_id')
                ->nullable()
                ->after('user_id')
                ->constrained('purchase_vendors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_vendor_id');
        });
    }
};
