<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Renewal & Extension (Sangoe TPV §28).
 *
 * Before contract/vendor expiry, a renewal is assessed from performance,
 * compliance, incidents, NCR, CAPA, strikes/violations (Rule 10 — performance
 * influences renewal), and decided: Renew / Renew with Conditions / Extend /
 * Requalify / Replace / Suspend / Exit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_renewals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // REN-YYYY-###
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->date('due_date')->nullable();
            // Snapshot of the inputs that drove the decision (VRS score, open NCR/
            // CAPA/violation counts, active strikes, band…).
            $table->json('assessment')->nullable();
            // Pending / Assessed / Decided
            $table->string('status', 16)->default('Pending')->index();
            // Renew / Renew_With_Conditions / Extend / Requalify / Replace / Suspend / Exit
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
        Schema::dropIfExists('tpv_renewals');
    }
};
