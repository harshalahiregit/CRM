<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Settings.
 *
 * `purchase_settings` is a tenant-scoped key/value store, mirroring the existing
 * inventory_config / task_settings pattern. The legacy module split these across
 * two tables with a hard-coded routing rule; that accident is deliberately not
 * reproduced — one store, one contract (PurchaseSettingService::DEFAULTS).
 *
 * `purchase_vendor_categories` replaces the static category list with a
 * Purchase-owned master. Both are Purchase-owned: no shared Vendor, no TPV.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_settings')) {
            Schema::create('purchase_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('key', 120);
                $table->text('value')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                // A stored row means "the tenant has configured this" — the
                // numbering helpers rely on presence, not just value.
                $table->unique(['tenant_id', 'key']);
            });
        }

        if (! Schema::hasTable('purchase_vendor_categories')) {
            Schema::create('purchase_vendor_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendor_categories');
        Schema::dropIfExists('purchase_settings');
    }
};
