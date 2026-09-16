<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The doctor identity behind the Internal Medical Flow.
 *
 * A doctor signs in as an ordinary User with role `doctor`; everything that is
 * specific to practising medicine — licence number, council, the signature that
 * gets stamped on every prescription — lives here rather than on `users`, which
 * is a shared entity no single module may reshape (TEAM-CONVENTIONS §2/§6).
 *
 * One profile per user. `modules` says which vendor sides the doctor may serve,
 * so ONE login can cover both TPV and Purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_doctor_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->unique();

            // Printed on every certificate — the licence number is what makes a
            // prescription verifiable, so it is required at the form layer.
            $table->string('license_no', 60)->nullable();
            $table->string('council', 160)->nullable();          // issuing medical council
            $table->string('qualification', 160)->nullable();    // MBBS, MD, AFIH …
            $table->string('designation', 120)->nullable();
            $table->string('clinic_name', 160)->nullable();
            $table->string('clinic_address', 255)->nullable();
            $table->string('phone', 40)->nullable();

            // Stamped onto the PDF. The signature is drawn once and reused; the
            // photo is the doctor's profile shot, NOT the per-exam camera capture.
            $table->string('signature_path')->nullable();
            $table->string('stamp_path')->nullable();
            $table->string('photo_path')->nullable();

            // Which vendor sides this login serves: ["tpv"], ["purchase"] or both.
            $table->json('modules')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_doctor_profiles');
    }
};
