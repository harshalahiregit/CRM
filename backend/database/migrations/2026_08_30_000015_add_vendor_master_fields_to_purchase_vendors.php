<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-5.3 — Purchase Vendor Master alignment.
 *
 * Adds the vendor-master profile/financial fields the shared Vendor Master
 * exposes, onto the Purchase-owned purchase_vendors table so the Purchase
 * Vendor Create/Edit screen is feature-complete. Purely additive: every column
 * is guarded by hasColumn, is nullable (bar currency's default), and no existing
 * column is touched or recreated. Nothing here references the shared vendors
 * table or vendor_id — this stays 100% Purchase-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            // Profile
            if (! Schema::hasColumn('purchase_vendors', 'balance')) {
                $table->decimal('balance', 15, 2)->nullable()->after('gst_number');
            }
            if (! Schema::hasColumn('purchase_vendors', 'balance_as_of')) {
                $table->date('balance_as_of')->nullable()->after('balance');
            }
            if (! Schema::hasColumn('purchase_vendors', 'currency')) {
                $table->string('currency', 8)->default('INR')->after('balance_as_of');
            }
            if (! Schema::hasColumn('purchase_vendors', 'language')) {
                $table->string('language', 40)->nullable()->after('currency');
            }
            // Financial
            if (! Schema::hasColumn('purchase_vendors', 'bank_details')) {
                $table->text('bank_details')->nullable()->after('pincode');
            }
            if (! Schema::hasColumn('purchase_vendors', 'payment_terms')) {
                $table->string('payment_terms', 120)->nullable()->after('bank_details');
            }
            // Return policy
            if (! Schema::hasColumn('purchase_vendors', 'return_policy')) {
                $table->text('return_policy')->nullable()->after('payment_terms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            foreach (['balance', 'balance_as_of', 'currency', 'language', 'bank_details', 'payment_terms', 'return_policy'] as $col) {
                if (Schema::hasColumn('purchase_vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
