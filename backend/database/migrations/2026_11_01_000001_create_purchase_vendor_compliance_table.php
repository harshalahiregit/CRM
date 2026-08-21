<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase compliance engine — the Purchase-side mirror of tpv_vendor_compliance
 * (parity rule), on its own table keyed to purchase_vendors. A per-vendor
 * register across the 14 categories; expiry drives status (Rule 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_vendor_compliance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->string('category', 40);
            $table->string('status', 24)->default('Under_Review');
            $table->text('requirement')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->string('evidence_path')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_vendor_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendor_compliance');
    }
};
