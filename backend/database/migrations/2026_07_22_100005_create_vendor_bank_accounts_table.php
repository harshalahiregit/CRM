<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · Item 2 — Profile Enhancement (Bank Details).
 *
 * Additive: a normalized store for the vendor's bank account, mirrored from the
 * Step-2 profile on save (the same pattern GST/PAN use with the vendor master).
 * New table only — no existing table is altered, so this is migration-safe and
 * fully reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('account_holder')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc')->nullable();
            $table->string('branch')->nullable();
            $table->string('account_type')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One current bank record per vendor per tenant (upserted on save).
            $table->unique(['tenant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bank_accounts');
    }
};
