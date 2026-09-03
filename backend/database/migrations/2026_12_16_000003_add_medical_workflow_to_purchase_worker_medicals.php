<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Purchase mirror of the Medical module (see the TPV migration alongside
 * this one for the reasoning). Purchase vendors get the same doctor portal, the
 * same quality check and the same certificate — the tables stay separate because
 * the two sides are separate registers, as everywhere else in this codebase.
 *
 * Unlike TPV, `purchase_worker_medicals` never carried a unique key on
 * (worker, exam_date), so history already accumulated freely here; attempt_no is
 * added for parity of meaning, not to fix a key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_worker_medicals', function (Blueprint $table) {
            $table->string('certificate_no', 40)->nullable()->after('id');
            $table->unsignedTinyInteger('attempt_no')->default(1)->after('exam_type');
            $table->string('origin', 20)->default('doctor_portal')->after('attempt_no');

            $table->unsignedBigInteger('doctor_user_id')->nullable()->after('recorded_by');
            $table->string('doctor_license_no', 60)->nullable()->after('doctor_user_id');
            $table->string('doctor_council', 160)->nullable()->after('doctor_license_no');
            $table->string('doctor_qualification', 160)->nullable()->after('doctor_council');
            $table->text('doctor_remarks')->nullable()->after('doctor_qualification');

            $table->unsignedSmallInteger('pulse_bpm')->nullable()->after('bp_diastolic');
            $table->unsignedTinyInteger('spo2')->nullable()->after('pulse_bpm');
            $table->decimal('temperature_c', 4, 1)->nullable()->after('spo2');
            $table->unsignedSmallInteger('respiratory_rate')->nullable()->after('temperature_c');
            $table->string('vision_left', 20)->nullable()->after('vision');
            $table->string('vision_right', 20)->nullable()->after('vision_left');
            $table->string('colour_vision', 20)->nullable()->after('vision_right');
            $table->string('hearing', 20)->nullable()->after('colour_vision');
            $table->json('investigations')->nullable()->after('hearing');
            $table->json('medical_history')->nullable()->after('investigations');
            $table->text('allergies')->nullable()->after('medical_history');
            $table->text('current_medication')->nullable()->after('allergies');

            $table->decimal('health_score', 3, 1)->nullable()->after('screening_band');
            $table->string('health_score_source', 10)->nullable()->after('health_score');
            $table->string('health_score_note', 255)->nullable()->after('health_score_source');

            $table->string('qc_status', 20)->default('Pending')->after('restrictions');
            $table->unsignedBigInteger('qc_by')->nullable()->after('qc_status');
            $table->timestamp('qc_at')->nullable()->after('qc_by');
            $table->string('qc_reason_code', 60)->nullable()->after('qc_at');
            $table->text('qc_note')->nullable()->after('qc_reason_code');
            $table->unsignedTinyInteger('iteration_count')->default(0)->after('qc_note');

            $table->unsignedBigInteger('previous_medical_id')->nullable()->after('iteration_count');
            $table->boolean('is_reexam')->default(false)->after('previous_medical_id');

            $table->string('geo_place', 200)->nullable()->after('geo_location');
            $table->string('pdf_path')->nullable()->after('capture_photo_path');

            $table->index(['tenant_id', 'qc_status']);
            $table->index('certificate_no');
            $table->index('doctor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_worker_medicals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'qc_status']);
            $table->dropIndex(['certificate_no']);
            $table->dropIndex(['doctor_user_id']);
            $table->dropColumn([
                'certificate_no', 'attempt_no', 'origin',
                'doctor_user_id', 'doctor_license_no', 'doctor_council', 'doctor_qualification', 'doctor_remarks',
                'pulse_bpm', 'spo2', 'temperature_c', 'respiratory_rate',
                'vision_left', 'vision_right', 'colour_vision', 'hearing',
                'investigations', 'medical_history', 'allergies', 'current_medication',
                'health_score', 'health_score_source', 'health_score_note',
                'qc_status', 'qc_by', 'qc_at', 'qc_reason_code', 'qc_note', 'iteration_count',
                'previous_medical_id', 'is_reexam', 'geo_place', 'pdf_path',
            ]);
        });
    }
};
