<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase worker competencies — the "No Competency, No Work" spine for the
 * Purchase workforce (mirror of the TPV §15 tpv_worker_competencies table).
 *
 * A worker's competencies (qualification / trade certificate / licence /
 * certification / skill), each with validity so expiry drives status. Purchase
 * has no work-package/activity model, so the required-competency source is the
 * tenant Settings key `workforce_required_competencies`; this table holds what a
 * worker actually HOLDS, which the gate matches against that requirement.
 *
 * Purchase-owned and completely independent of the TPV engine. Row-level
 * multi-tenancy only (no DB foreign keys), matching the rest of the module.
 * Dated after every existing Purchase migration (latest was
 * 2026_12_01_000001_add_risk_to_purchase_vendors) so it always runs last.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_worker_competencies')) {
            return;
        }

        Schema::create('purchase_worker_competencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->unsignedBigInteger('purchase_worker_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');                            // e.g. "Electrical L2", "Rigger Licence"
            // Qualification / Trade_Certificate / Licence / Certification / Skill
            $table->string('category', 32)->default('Skill');
            $table->string('authority')->nullable();           // issuing body
            $table->string('reference_no')->nullable();
            $table->string('skill_level')->nullable();         // Beginner/Competent/Expert or L1-L3
            $table->decimal('experience_years', 4, 1)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->string('evidence_path')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'purchase_worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_worker_competencies');
    }
};
