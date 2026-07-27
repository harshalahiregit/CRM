<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-vendor contacts — the procurement module's OWN contact list for a
 * vendor engaged for Purchase. Deliberately independent from tpv_contacts: the
 * Purchase and TPV modules never share business tables, only the vendor identity
 * (vendor_id) in the shared Vendor Master. Single-primary per vendor is enforced
 * in PurchaseContactService (no partial-unique index). Row-level multi-tenancy;
 * no DB foreign keys, matching module convention.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The owning vendor (shared Vendor Master identity). Ownership/tenant
            // integrity enforced in the service/controller, not the DB.
            $table->unsignedBigInteger('vendor_id')->index();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('alternate_mobile')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('Active')->index();   // Active | Inactive

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_contacts');
    }
};
