<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence locker needs the same Purchase-vendor column the other shared
 * registers just got (migration 000007) — it was missed there.
 *
 * compliance_evidence.vendor_id points at the shared `vendors` table and is
 * validated as a bare nullable integer, so filing evidence with a
 * purchase_vendors id would not fail: it would attribute that evidence to
 * whichever TPV vendor held the same number. Compliance evidence filed against
 * the wrong company is exactly the record you cannot afford to get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_evidence') || Schema::hasColumn('compliance_evidence', 'purchase_vendor_id')) {
            return;
        }

        Schema::table('compliance_evidence', function (Blueprint $t) {
            $t->unsignedBigInteger('purchase_vendor_id')->nullable()->after('vendor_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('compliance_evidence') || ! Schema::hasColumn('compliance_evidence', 'purchase_vendor_id')) {
            return;
        }

        Schema::table('compliance_evidence', function (Blueprint $t) {
            $t->dropColumn('purchase_vendor_id');
        });
    }
};
