<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §7 Due-Diligence checklist. A per-vendor verification record covering the
 * checks the doc names: company / document / licence / insurance verification,
 * background checks and reference checks — each tracked as a status, with a
 * free-form findings blob for the detail behind each check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_due_diligences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();

            // Each check: Pending / Verified / Failed / Not_Applicable.
            $table->string('company_verification', 20)->default('Pending');
            $table->string('document_verification', 20)->default('Pending');
            $table->string('licence_verification', 20)->default('Pending');
            $table->string('insurance_verification', 20)->default('Pending');
            $table->string('background_check', 20)->default('Pending');
            $table->string('reference_check', 20)->default('Pending');

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
        Schema::dropIfExists('tpv_due_diligences');
    }
};
