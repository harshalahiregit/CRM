<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: the medical examination's depth.
 *
 * The Purchase worker wizard mirrors TPV's, which records vitals, a scored
 * mental-health screening, the examiner's signature and the §16 legal capture
 * (device photo, geolocation, IP). purchase_worker_medicals carried none of
 * them, so the wizard had to fold that detail into `remarks` as prose — which
 * reads back but cannot be queried, charted, or used by the fitness gate.
 *
 * Column names follow tpv_worker_medicals exactly, so the two modules answer the
 * same question with the same word and a report can span both.
 *
 * Nullable throughout: every existing row predates these, and a medical is
 * legitimately recorded with only a date and a verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_worker_medicals', function (Blueprint $table) {
            // Who recorded it, and what kind of examination it was.
            $table->unsignedBigInteger('recorded_by')->nullable()->after('created_by');
            $table->string('exam_type', 60)->nullable()->after('exam_date');
            $table->string('clinic_name', 150)->nullable()->after('examiner_name');

            // The currency window. `expiry_date` already exists and remains the
            // column the gate reads; valid_until is TPV's name for the same idea
            // and is kept in step by the service, not by a second source of truth.
            $table->date('valid_until')->nullable()->after('expiry_date');

            // Vitals. Numeric rather than free text so BMI and the fitness bands
            // are computed from data instead of re-parsed out of a sentence.
            $table->decimal('height_cm', 5, 1)->nullable()->after('blood_group');
            $table->decimal('weight_kg', 5, 1)->nullable()->after('height_cm');
            $table->unsignedSmallInteger('bp_systolic')->nullable()->after('weight_kg');
            $table->unsignedSmallInteger('bp_diastolic')->nullable()->after('bp_systolic');
            $table->string('vision', 60)->nullable()->after('bp_diastolic');

            // Mental-health screening: the raw answers plus the derived score and
            // band. The responses are kept so a band can be re-derived if the
            // scoring changes; the band is stored so history is not silently
            // rewritten when it does.
            $table->json('screening_responses')->nullable()->after('vision');
            $table->unsignedSmallInteger('screening_score')->nullable()->after('screening_responses');
            $table->string('screening_band', 20)->nullable()->after('screening_score');

            // Proof: the examiner's signature, and the §16 capture that ties the
            // record to a place and a device at a moment.
            $table->string('signature_path')->nullable()->after('certificate_path');
            $table->string('capture_photo_path')->nullable()->after('signature_path');
            $table->string('system_ip', 45)->nullable()->after('capture_photo_path');
            $table->string('geo_location', 120)->nullable()->after('system_ip');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_worker_medicals', function (Blueprint $table) {
            $table->dropColumn([
                'recorded_by', 'exam_type', 'clinic_name', 'valid_until',
                'height_cm', 'weight_kg', 'bp_systolic', 'bp_diastolic', 'vision',
                'screening_responses', 'screening_score', 'screening_band',
                'signature_path', 'capture_photo_path', 'system_ip', 'geo_location',
            ]);
        });
    }
};
