<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The step records behind worker registration:
 *   step 2 — medical exam + mental-health screening (gates on fitness)
 *   step 3 — HSSE induction/training (gates on passed)
 *   step 4 — PPE issuance (gates on the mandatory set)
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Step 2 — medical ──
        Schema::create('tpv_worker_medicals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id');
            $table->unsignedBigInteger('recorded_by')->nullable();

            $table->string('exam_type')->default('internal');   // internal | external
            $table->date('exam_date')->nullable();
            $table->string('examiner_name')->nullable();
            $table->string('clinic_name')->nullable();

            // Physical measurements
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->string('vision')->nullable();

            // Mental-health screening — scored questionnaire, banded for triage.
            $table->json('screening_responses')->nullable();
            $table->unsignedSmallInteger('screening_score')->nullable();
            $table->string('screening_band')->nullable();       // Low | Moderate | High

            // Outcome — Unfit blocks badge issue.
            $table->string('fitness_status')->nullable();       // Fit | Fit_With_Restrictions | Unfit
            $table->text('restrictions')->nullable();
            $table->string('signature_path')->nullable();

            $table->timestamps();
            $table->unique('tpv_worker_id');   // one current medical per worker
        });

        // ── Step 3 — HSSE induction ──
        Schema::create('tpv_worker_inductions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id');
            $table->unsignedBigInteger('recorded_by')->nullable();

            $table->string('trainer_name')->nullable();
            $table->date('training_date')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->json('topics')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->boolean('passed')->default(false);

            // Attendance evidence captured at the session.
            $table->string('photo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('thumbprint_path')->nullable();

            $table->timestamps();
            $table->unique('tpv_worker_id');   // one current induction per worker
        });

        // ── Step 4 — PPE issuance ──
        Schema::create('tpv_worker_ppe_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id')->index();
            $table->unsignedBigInteger('issued_by')->nullable();
            // Reserved for Shivam's Inventory (stock decrement) — nullable FK, no
            // constraint yet so wiring it later is a one-line migration (§2).
            $table->unsignedBigInteger('inventory_item_id')->nullable()->index();

            $table->string('item');                            // helmet | safety_shoes | ...
            $table->unsignedSmallInteger('qty')->default(1);
            $table->string('size')->nullable();
            $table->date('issued_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_worker_ppe_issues');
        Schema::dropIfExists('tpv_worker_inductions');
        Schema::dropIfExists('tpv_worker_medicals');
    }
};
