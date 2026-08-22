<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Renewal & Extension — the Purchase-side mirror of tpv_renewals
 * (parity rule). A renewal is assessed from the vendor's performance index +
 * open governance items (NCR/CAPA/violations) and decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_renewals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // PREN-YYYY-###
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->json('assessment')->nullable();
            $table->string('status', 16)->default('Pending')->index();
            $table->string('decision', 24)->nullable();
            $table->text('conditions')->nullable();
            $table->date('new_end_date')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_renewals');
    }
};
