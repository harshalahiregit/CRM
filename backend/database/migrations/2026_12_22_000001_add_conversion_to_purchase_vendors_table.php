<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promoting a temporary Purchase Vendor to permanent.
 *
 * TPV has had this since its temporary-access work landed — `vendors` carries
 * converted_to_permanent_at and converted_by, and an admin presses a button.
 * Purchase had the temporary side (a registration type, an access window, an
 * expiry that locks the portal) and no way out of it at all.
 *
 * The gap was worse than a missing feature: the vendor edit form offers
 * "Permanent" in its type dropdown, and picking it answered 200 while changing
 * nothing that matters. isTemporary() reads registration_type first and the
 * update request never accepted that field, so the screen said Permanent while
 * the row stayed Temporary and the account still expired underneath them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            // When, and by whom. Both nullable: every existing row predates the
            // feature, and a vendor that was never temporary never converts.
            $table->timestamp('converted_to_permanent_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('converted_by')->nullable()->after('converted_to_permanent_at');

            // "Which temporary vendors did we make permanent, and when" is the
            // question an auditor asks, and it reads the whole table without it.
            $table->index(['tenant_id', 'converted_to_permanent_at'], 'pv_tenant_converted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            $table->dropIndex('pv_tenant_converted_idx');
            $table->dropColumn(['converted_to_permanent_at', 'converted_by']);
        });
    }
};
