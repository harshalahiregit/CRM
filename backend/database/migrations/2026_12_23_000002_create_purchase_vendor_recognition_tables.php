<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Awards and referrals for a Purchase vendor.
 *
 * The last two entries in the Purchase vendor workspace's Performance group with
 * nothing behind them. Everything else there already had a backend and only
 * wanted a tab: risk lives in columns on purchase_vendors, the performance index
 * is computed by PurchaseVendorPerformanceService, and penalties are the
 * violations register.
 *
 * Purchase-owned tables, not a second key on TPV's. `vendor_awards` and
 * `vendor_referrals` exist and are foreign-keyed to `vendors` — the TPV master —
 * and hanging purchase_vendor_id off them would put two unrelated vendor
 * populations in one table with half its rows null either way. The two modules
 * keep separate masters on purpose; recognition follows the master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_vendor_awards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('purchase_vendor_id');

            $table->string('title');
            $table->string('category', 60)->nullable();      // Quality, Safety, Delivery…
            $table->text('description')->nullable();
            $table->date('awarded_on');
            $table->unsignedBigInteger('granted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // "What has this vendor been recognised for" is the only question
            // asked of this table, and it is asked from the vendor's own page.
            $table->index(['tenant_id', 'purchase_vendor_id'], 'pva_tenant_vendor_idx');
        });

        Schema::create('purchase_vendor_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // Who made the introduction. Named for what it is: the row is about
            // a company that is NOT yet a vendor, introduced by one that is.
            $table->unsignedBigInteger('referred_by_purchase_vendor_id');

            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->text('note')->nullable();

            // Pending → Contacted → Onboarded / Declined.
            $table->string('status', 30)->default('Pending');

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['tenant_id', 'referred_by_purchase_vendor_id'],
                'pvr_tenant_referrer_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendor_referrals');
        Schema::dropIfExists('purchase_vendor_awards');
    }
};
