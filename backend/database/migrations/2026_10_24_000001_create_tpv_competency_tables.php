<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Competency & Training (Sangoe TPV §15).
 *
 * A worker's competencies (qualification / trade certificate / licence /
 * certification / skill) and typed training records, each with validity so
 * expiry drives status. The Skill Matrix (Worker × Activity × Competency ×
 * Validity) is derived from these against tpv_activities.required_competency.
 * Additive — the existing single HSSE induction (tpv_worker_inductions) stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_worker_competencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id')->index();
            $table->string('name');                            // e.g. "Electrical L2", "Rigger Licence"
            // Qualification / Trade_Certificate / Licence / Certification / Skill
            $table->string('category', 32)->default('Skill');
            $table->string('authority')->nullable();           // issuing body
            $table->string('reference_no')->nullable();
            $table->string('skill_level')->nullable();         // Beginner/Competent/Expert or L1-L3
            $table->date('issued_date')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->string('evidence_path')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tpv_worker_trainings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id')->index();
            // Site_Induction / HSE_Induction / Toolbox / Fire / Work_At_Height /
            // Electrical / Confined_Space / Lifting / Equipment / Emergency_Response / Other
            $table->string('training_type', 40);
            $table->string('provider')->nullable();
            $table->date('completed_date')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->boolean('passed')->default(true);
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('certificate_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_worker_trainings');
        Schema::dropIfExists('tpv_worker_competencies');
    }
};
