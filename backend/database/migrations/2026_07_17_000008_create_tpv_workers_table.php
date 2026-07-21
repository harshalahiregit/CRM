<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV workforce — individual workers employed by an onboarded vendor, taken
 * through a 5-step registration: profile → medical → HSSE induction → PPE →
 * entry badge.
 *
 * The badge (number + QR token) lives on the worker: one active card at a time,
 * and reissuing rotates the token so a lost card's QR stops resolving.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tpv_workers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The employing vendor — must be Active (onboarding approved) before
            // any of its workers can be badged. Enforced in the service.
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('worker_code');

            // ── Step 1 — profile ──
            $table->string('name');
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
            $table->string('designation')->nullable();
            $table->string('skill_category')->nullable();   // Skilled | Semi_Skilled | Unskilled | Supervisor
            $table->string('aadhar_number')->nullable();
            $table->string('mobile')->nullable();
            $table->string('blood_group')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('photo_path')->nullable();

            // ── Wizard + lifecycle ──
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->string('status')->default('Draft')->index();

            // ── Step 5 — entry card / QR badge ──
            $table->string('badge_number')->nullable();
            $table->string('qr_token', 64)->nullable()->unique();  // resolves the public gate scan
            $table->timestamp('badge_issued_at')->nullable();
            $table->unsignedBigInteger('badge_issued_by')->nullable();
            $table->date('badge_valid_until')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'worker_code']);
            // Duplicate-worker guard: one Aadhar per tenant (NULLs stay allowed).
            $table->unique(['tenant_id', 'aadhar_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_workers');
    }
};
