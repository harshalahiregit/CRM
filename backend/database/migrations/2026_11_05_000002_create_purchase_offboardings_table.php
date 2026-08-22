<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Offboarding / Closure — the Purchase-side mirror of tpv_offboardings
 * (parity rule). A controlled exit checklist that, on completion, applies the
 * final vendor status through the shared PurchaseVendorService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_offboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // POFF-YYYY-###
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->text('reason')->nullable();
            $table->json('checklist')->nullable();
            $table->string('status', 16)->default('In_Progress')->index();
            $table->string('final_status', 16)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_offboardings');
    }
};
