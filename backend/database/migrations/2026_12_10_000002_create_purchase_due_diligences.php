<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-side Due-Diligence checklist — a per-vendor verification record
 * mirroring tpv_due_diligences: company / document / licence / insurance
 * verification, background & reference checks, plus previous performance,
 * incident and compliance history. Each check tracks a status; the record rolls
 * up to a single Cleared / Rejected outcome. Keyed by tenant_id +
 * purchase_vendor_id. Idempotent so it is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_due_diligences')) {
            return;
        }

        Schema::create('purchase_due_diligences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->index();

            // Each check: Pending / Verified / Failed / Not_Applicable.
            $table->string('company_verification', 20)->default('Pending');
            $table->string('document_verification', 20)->default('Pending');
            $table->string('licence_verification', 20)->default('Pending');
            $table->string('insurance_verification', 20)->default('Pending');
            $table->string('background_check', 20)->default('Pending');
            $table->string('reference_check', 20)->default('Pending');
            $table->string('previous_performance', 20)->default('Pending');
            $table->string('incident_history', 20)->default('Pending');
            $table->string('compliance_history', 20)->default('Pending');

            // Structured detail per check (evidence, referee responses, etc.).
            $table->json('findings')->nullable();
            $table->text('notes')->nullable();

            // Rollup: Pending / In_Progress / Cleared / Rejected.
            $table->string('status', 20)->default('Pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_due_diligences');
    }
};
