<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Medical module proper, on the TPV side.
 *
 * Three things the register could not express before:
 *  1. WHO examined — a doctor login (not a free-text examiner name), with the
 *     licence stamped onto the record so the PDF prescription is verifiable.
 *  2. WHAT was examined — the detailed examination form: vitals beyond height/
 *     weight/BP, the sensory tests, the investigations, the declared history.
 *  3. WHAT HAPPENED TO IT — the quality-check verdict (Approve / Reject / Hold),
 *     the back-and-forth iteration count, and the re-examination chain.
 *
 * The unique key moves from (worker, exam_date) to (worker, exam_date, attempt_no)
 * so a SAME-DAY re-examination is a new record instead of overwriting the one
 * that was just rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            /* ── Identity of the examination ─────────────────────────────── */
            $table->string('certificate_no', 40)->nullable()->after('id');
            $table->unsignedTinyInteger('attempt_no')->default(1)->after('exam_type');
            // Where the record came in from, which is not the same question as
            // internal/external: an external certificate can arrive one at a time
            // from a vendor or in a bulk sheet from an admin.
            $table->string('origin', 20)->default('doctor_portal')->after('attempt_no');

            /* ── The examining doctor (internal flow) ────────────────────── */
            $table->unsignedBigInteger('doctor_user_id')->nullable()->after('recorded_by');
            $table->string('doctor_license_no', 60)->nullable()->after('doctor_user_id');
            $table->string('doctor_council', 160)->nullable()->after('doctor_license_no');
            $table->string('doctor_qualification', 160)->nullable()->after('doctor_council');
            $table->text('doctor_remarks')->nullable()->after('doctor_qualification');

            /* ── Detailed examination form ───────────────────────────────── */
            $table->unsignedSmallInteger('pulse_bpm')->nullable()->after('bp_diastolic');
            $table->unsignedTinyInteger('spo2')->nullable()->after('pulse_bpm');
            $table->decimal('temperature_c', 4, 1)->nullable()->after('spo2');
            $table->unsignedSmallInteger('respiratory_rate')->nullable()->after('temperature_c');
            $table->string('blood_group', 8)->nullable()->after('respiratory_rate');
            $table->string('vision_left', 20)->nullable()->after('vision');
            $table->string('vision_right', 20)->nullable()->after('vision_left');
            $table->string('colour_vision', 20)->nullable()->after('vision_right');
            $table->string('hearing', 20)->nullable()->after('colour_vision');
            // Investigations: [{name, result, remarks}] — a list rather than a
            // column each, because the panel differs by site and by job.
            $table->json('investigations')->nullable()->after('hearing');
            // Declared history: chronic illness, surgery, habits and the yes/no
            // questionnaire, all as one document.
            $table->json('medical_history')->nullable()->after('investigations');
            $table->text('allergies')->nullable()->after('medical_history');
            $table->text('current_medication')->nullable()->after('allergies');

            /* ── Health score (profile integration) ──────────────────────── */
            // Out of 10, one decimal. Computed from the examination; a doctor may
            // override, in which case the source records that it was not derived.
            $table->decimal('health_score', 3, 1)->nullable()->after('screening_band');
            $table->string('health_score_source', 10)->nullable()->after('health_score');
            $table->string('health_score_note', 255)->nullable()->after('health_score_source');

            /* ── Quality check ───────────────────────────────────────────── */
            $table->string('qc_status', 20)->default('Pending')->after('restrictions');
            $table->unsignedBigInteger('qc_by')->nullable()->after('qc_status');
            $table->timestamp('qc_at')->nullable()->after('qc_by');
            $table->string('qc_reason_code', 60)->nullable()->after('qc_at');
            $table->text('qc_note')->nullable()->after('qc_reason_code');
            // How many times this certificate has gone back and forth. Capped by
            // the configured maximum (10 by default).
            $table->unsignedTinyInteger('iteration_count')->default(0)->after('qc_note');

            /* ── Re-examination chain ────────────────────────────────────── */
            $table->unsignedBigInteger('previous_medical_id')->nullable()->after('iteration_count');
            $table->boolean('is_reexam')->default(false)->after('previous_medical_id');

            /* ── Legal capture + output ──────────────────────────────────── */
            // The coordinates stay raw in geo_location; this is the resolved
            // human-readable place, so a certificate reads "Andheri, Mumbai"
            // instead of a pair of decimals.
            $table->string('geo_place', 200)->nullable()->after('geo_location');
            // The generated prescription. Regenerated on demand, cached here.
            $table->string('pdf_path')->nullable()->after('capture_photo_path');

            $table->index(['tenant_id', 'qc_status']);
            $table->index('certificate_no');
            $table->index('doctor_user_id');
        });

        // A same-day re-test must be able to exist alongside the record it
        // replaces, so the attempt number joins the key.
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropUnique(['tpv_worker_id', 'exam_date']);
            $table->unique(['tpv_worker_id', 'exam_date', 'attempt_no'], 'tpv_med_worker_date_attempt_uq');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropUnique('tpv_med_worker_date_attempt_uq');
            $table->unique(['tpv_worker_id', 'exam_date']);
        });

        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'qc_status']);
            $table->dropIndex(['certificate_no']);
            $table->dropIndex(['doctor_user_id']);
            $table->dropColumn([
                'certificate_no', 'attempt_no', 'origin',
                'doctor_user_id', 'doctor_license_no', 'doctor_council', 'doctor_qualification', 'doctor_remarks',
                'pulse_bpm', 'spo2', 'temperature_c', 'respiratory_rate', 'blood_group',
                'vision_left', 'vision_right', 'colour_vision', 'hearing',
                'investigations', 'medical_history', 'allergies', 'current_medication',
                'health_score', 'health_score_source', 'health_score_note',
                'qc_status', 'qc_by', 'qc_at', 'qc_reason_code', 'qc_note', 'iteration_count',
                'previous_medical_id', 'is_reexam', 'geo_place', 'pdf_path',
            ]);
        });
    }
};
