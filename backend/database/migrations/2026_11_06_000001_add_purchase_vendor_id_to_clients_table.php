<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-side parity for the vendor workspace Customer tab.
 *
 * The TPV workspace links customers through clients.vendor_id; Purchase is a
 * separate DB entity (purchase_vendors), so it gets its OWN link column here,
 * mirroring vendor_id exactly: nullable, indexed, NO foreign key (the Customer
 * module is Zafar's and neither vendor entity is owned by it). A client may be
 * linked to a TPV vendor, a Purchase vendor, both, or neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'purchase_vendor_id')) {
                $table->unsignedBigInteger('purchase_vendor_id')->nullable()->after('vendor_id');
                $table->index('purchase_vendor_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'purchase_vendor_id')) {
                $table->dropIndex(['purchase_vendor_id']);
                $table->dropColumn('purchase_vendor_id');
            }
        });
    }
};
