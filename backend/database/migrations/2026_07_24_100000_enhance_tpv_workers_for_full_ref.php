<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            if (!Schema::hasColumn('tpv_workers', 'email')) {
                $table->string('email')->nullable()->after('mobile');
            }
            if (!Schema::hasColumn('tpv_workers', 'age')) {
                $table->unsignedSmallInteger('age')->nullable()->after('dob');
            }
            if (!Schema::hasColumn('tpv_workers', 'age_reason')) {
                $table->string('age_reason')->nullable()->after('age');
            }
            if (!Schema::hasColumn('tpv_workers', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('status');
            }

            // Step 2 — Medical
            if (!Schema::hasColumn('tpv_workers', 'medical_status')) {
                $table->unsignedTinyInteger('medical_status')->default(0)->after('is_active'); // 0=Pending, 1=Completed, 2=Skipped
            }
            if (!Schema::hasColumn('tpv_workers', 'medical_type')) {
                $table->string('medical_type')->nullable()->after('medical_status'); // internal | external | skip
            }
            if (!Schema::hasColumn('tpv_workers', 'doctor_name')) {
                $table->string('doctor_name')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'organization_name')) {
                $table->string('organization_name')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'doctor_registration')) {
                $table->string('doctor_registration')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'eyesight')) {
                $table->string('eyesight')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'height')) {
                $table->string('height')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'weight')) {
                $table->string('weight')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'blood_pressure')) {
                $table->string('blood_pressure')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'height_phobia')) {
                $table->string('height_phobia', 10)->default('no');
            }
            if (!Schema::hasColumn('tpv_workers', 'heart_disease')) {
                $table->string('heart_disease', 10)->default('no');
            }
            if (!Schema::hasColumn('tpv_workers', 'habits')) {
                $table->string('habits')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'handicapped')) {
                $table->string('handicapped', 10)->default('no');
            }
            if (!Schema::hasColumn('tpv_workers', 'system_ip')) {
                $table->string('system_ip', 45)->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'geo_location')) {
                $table->string('geo_location')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_version')) {
                $table->unsignedSmallInteger('mh_version')->default(1);
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_q1')) {
                $table->unsignedSmallInteger('mh_q1')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_q2')) {
                $table->unsignedSmallInteger('mh_q2')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_q3')) {
                $table->unsignedSmallInteger('mh_q3')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_q4')) {
                $table->unsignedSmallInteger('mh_q4')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_q5')) {
                $table->unsignedSmallInteger('mh_q5')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_q6')) {
                $table->unsignedSmallInteger('mh_q6')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_score')) {
                $table->string('mh_score')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_risk')) {
                $table->string('mh_risk')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'mh_flag')) {
                $table->unsignedSmallInteger('mh_flag')->default(0);
            }
            if (!Schema::hasColumn('tpv_workers', 'doctor_comments')) {
                $table->text('doctor_comments')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'physical_score')) {
                $table->string('physical_score')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'medical_result')) {
                $table->string('medical_result')->nullable(); // fit | unfit | conditional
            }
            if (!Schema::hasColumn('tpv_workers', 'signature_file')) {
                $table->string('signature_file')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'external_doctor_name')) {
                $table->string('external_doctor_name')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'external_doctor_regid')) {
                $table->string('external_doctor_regid')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'external_pdf')) {
                $table->string('external_pdf')->nullable();
            }

            // Step 3 — Induction
            if (!Schema::hasColumn('tpv_workers', 'induction_status')) {
                $table->unsignedTinyInteger('induction_status')->default(0); // 0=Pending, 1=Completed, 2=Skipped
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_type')) {
                $table->string('induction_type')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'trainer')) {
                $table->string('trainer')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_location')) {
                $table->string('induction_location')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_start')) {
                $table->timestamp('induction_start')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_end')) {
                $table->timestamp('induction_end')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_duration')) {
                $table->unsignedInteger('induction_duration')->nullable(); // in minutes
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_device')) {
                $table->string('induction_device')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_photo')) {
                $table->string('induction_photo')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_signature')) {
                $table->string('induction_signature')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_thumb')) {
                $table->string('induction_thumb')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'induction_proof_file')) {
                $table->string('induction_proof_file')->nullable();
            }

            // Step 4 — PPE
            if (!Schema::hasColumn('tpv_workers', 'ppe_status')) {
                $table->unsignedTinyInteger('ppe_status')->default(0); // 0=Pending, 1=Issued, 2=Skipped
            }
            if (!Schema::hasColumn('tpv_workers', 'ppe_items')) {
                $table->text('ppe_items')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'ppe_issued_to')) {
                $table->string('ppe_issued_to')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'ppe_remarks')) {
                $table->text('ppe_remarks')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'ppe_issued_at')) {
                $table->timestamp('ppe_issued_at')->nullable();
            }

            // Step 5 — Card Status & 3-Punch System
            if (!Schema::hasColumn('tpv_workers', 'card_status')) {
                $table->unsignedTinyInteger('card_status')->default(0); // 0=Pending, 1=Complete, 2=Skipped
            }
            if (!Schema::hasColumn('tpv_workers', 'card_issued_at')) {
                $table->timestamp('card_issued_at')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_count')) {
                $table->unsignedTinyInteger('punch_count')->default(0); // 0 to 3
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_1_at')) {
                $table->timestamp('punch_1_at')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_1_reason')) {
                $table->text('punch_1_reason')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_2_at')) {
                $table->timestamp('punch_2_at')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_2_reason')) {
                $table->text('punch_2_reason')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_3_at')) {
                $table->timestamp('punch_3_at')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_3_reason')) {
                $table->text('punch_3_reason')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'punch_log')) {
                $table->json('punch_log')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'approval_status')) {
                $table->string('approval_status')->default('Pending')->index(); // Pending | Approved | Rejected | On_Hold | Resubmit_Required
            }
            if (!Schema::hasColumn('tpv_workers', 'approval_remarks')) {
                $table->text('approval_remarks')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('tpv_workers', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            // No destructive drop needed for migration rollback safety
        });
    }
};
