<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Vendors — the Purchase module's OWN vendor master, a completely
 * independent business entity from the shared `vendors` table and from TPV.
 * Every Purchase table keys to purchase_vendors.id via purchase_vendor_id.
 * Row-level multi-tenancy; no DB foreign keys (module convention).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Portal login linkage (a User with the purchase-vendor portal role).
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('account_manager_id')->nullable()->index();

            $table->string('purchase_vendor_code');
            $table->string('company_name');
            $table->string('legal_name')->nullable();
            $table->string('vendor_type')->default('standard');   // standard | temporary

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('category')->nullable();

            $table->string('registration_number')->nullable();
            $table->string('gst_number')->nullable();
            $table->string('pan_number')->nullable();

            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('pincode')->nullable();

            $table->string('status')->default('Draft')->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();

            // Portal access token (temporary/self-service access), mirroring the
            // shared vendor portal mechanics but on the Purchase-owned entity.
            $table->string('access_token', 64)->nullable()->unique();
            $table->timestamp('access_expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'purchase_vendor_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendors');
    }
};
