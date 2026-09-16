<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medical examinations for everyone who is not a vendor worker.
 *
 * The doctor portal could only examine TPV and Purchase workers, because those
 * are the only two registers that exist — each vendor side keeps its own
 * mirrored `*_worker_medicals` table, and there was simply nowhere to file an
 * examination of an internal employee, a client visiting site, or a contractor
 * signing in at the gate for the afternoon.
 *
 * Rather than mirror that 69-column table a third, fourth and fifth time, this
 * is ONE general register with a polymorphic subject. The three audiences it
 * serves differ only in who the person is, never in what an examination
 * records — the same vitals, the same fitness verdict, the same quality check —
 * so one table with a subject type is honest, and three near-identical tables
 * would not be.
 *
 * The vendor registers are deliberately left alone. They carry vendor-specific
 * columns, their own QC flows and their own module's isolation rules; folding
 * them in here would be a rewrite of two working modules to save a table.
 *
 * `medical_visitors` exists because a site visitor is, by definition, somebody
 * the system has never met. They have no user account and no client record, so
 * there is nothing to point a subject at until one is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_visitors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('id_proof_type', 40)->nullable();
            $table->string('id_proof_number', 60)->nullable();
            // Why they are on site — the thing a gate log is actually for.
            $table->string('purpose', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('general_medicals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // user = internal team member · client_contact = a client's person
            // · visitor = a medical_visitors row.
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();

            /* ── The examination ─────────────────────────────────────── */
            $table->date('exam_date');
            $table->string('exam_type', 40)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('fitness_status', 40)->nullable();
            $table->string('blood_group', 10)->nullable();

            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->unsignedInteger('bp_systolic')->nullable();
            $table->unsignedInteger('bp_diastolic')->nullable();
            $table->unsignedInteger('pulse_bpm')->nullable();
            $table->unsignedInteger('spo2')->nullable();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->unsignedInteger('respiratory_rate')->nullable();
            $table->string('vision_left', 20)->nullable();
            $table->string('vision_right', 20)->nullable();
            $table->string('colour_vision', 40)->nullable();
            $table->string('hearing', 40)->nullable();

            $table->text('investigations')->nullable();
            $table->text('medical_history')->nullable();
            $table->text('allergies')->nullable();
            $table->text('current_medication')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('doctor_remarks')->nullable();
            $table->string('remarks', 500)->nullable();

            $table->decimal('health_score', 4, 1)->nullable();
            $table->string('health_score_source', 20)->nullable();
            $table->string('health_score_note', 255)->nullable();

            /* ── Who signed it, and the proof ────────────────────────── */
            $table->unsignedBigInteger('doctor_user_id')->nullable();
            $table->string('examiner_name', 160)->nullable();
            $table->string('doctor_license_no', 60)->nullable();
            $table->string('doctor_council', 160)->nullable();
            $table->string('doctor_qualification', 160)->nullable();
            $table->string('clinic_name', 160)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->string('capture_photo_path', 255)->nullable();
            $table->string('system_ip', 60)->nullable();
            $table->string('geo_location', 120)->nullable();
            $table->string('geo_place', 255)->nullable();

            /* ── The certificate ─────────────────────────────────────── */
            $table->string('certificate_no', 60)->nullable();
            $table->string('certificate_path', 255)->nullable();
            $table->string('document_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('origin', 30)->nullable();

            /* ── Quality check, same vocabulary as the vendor registers ─ */
            $table->string('qc_status', 20)->nullable();
            $table->unsignedBigInteger('qc_by')->nullable();
            $table->timestamp('qc_at')->nullable();
            $table->string('qc_reason_code', 60)->nullable();
            $table->text('qc_note')->nullable();
            $table->unsignedInteger('iteration_count')->default(0);

            /* ── Re-examination chain ────────────────────────────────── */
            $table->unsignedBigInteger('previous_medical_id')->nullable();
            $table->boolean('is_reexam')->default(false);
            $table->unsignedInteger('attempt_no')->default(1);

            $table->timestamps();
            $table->softDeletes();

            // The query this table exists to answer: everything for one person.
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'gm_subject_idx');
            $table->index(['tenant_id', 'exam_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_medicals');
        Schema::dropIfExists('medical_visitors');
    }
};
