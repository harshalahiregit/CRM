<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: the worker record itself.
 *
 * Only the columns that genuinely belong ON the worker are added here. TPV's
 * tpv_workers is one wide row carrying its medical, induction, PPE and training
 * state as well; Purchase keeps those normalised in purchase_worker_medicals /
 * _inductions / _ppe_issues / _trainings, and duplicating them onto the worker
 * would create a second, drifting answer to the same question. So the medical_*,
 * induction_*, ppe_* and training_* columns are deliberately NOT copied.
 *
 * What is copied is what has no other home: identity details, the site-access
 * discipline ladder (card + punches), and the employment facts a badge is issued
 * against.
 *
 * All nullable — every existing worker predates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_workers', function (Blueprint $table) {
            // Identity / personal details. NOTE: photo_path already exists on
            // this table — the worker photo was always storable, it simply had
            // no endpoint or UI reaching it.
            $table->string('blood_group', 10)->nullable()->after('dob');
            $table->string('skill_category', 120)->nullable()->after('designation');
            $table->string('trade', 120)->nullable()->after('skill_category');
            $table->string('emergency_contact', 150)->nullable()->after('phone');
            $table->string('emergency_phone', 30)->nullable()->after('emergency_contact');
            // Why an out-of-range age was allowed. Recording the age without the
            // reason is what makes an exception indistinguishable from an error.
            $table->string('age_reason', 255)->nullable()->after('dob');

            // Site-access discipline. A punch is a safety violation; three ends
            // the badge. The individual punch rows carry when and why, because
            // "punch_count = 2" alone cannot be appealed or audited.
            $table->string('card_status', 30)->nullable()->after('badge_valid_until');
            $table->timestamp('card_issued_at')->nullable()->after('card_status');
            $table->unsignedTinyInteger('punch_count')->default(0)->after('card_issued_at');
            $table->timestamp('punch_1_at')->nullable()->after('punch_count');
            $table->string('punch_1_reason', 255)->nullable()->after('punch_1_at');
            $table->timestamp('punch_2_at')->nullable()->after('punch_1_reason');
            $table->string('punch_2_reason', 255)->nullable()->after('punch_2_at');
            $table->timestamp('punch_3_at')->nullable()->after('punch_2_reason');
            $table->string('punch_3_reason', 255)->nullable()->after('punch_3_at');

            // Employment facts a badge is issued against.
            $table->string('bocw_number', 60)->nullable()->after('id_proof_number');
            $table->decimal('experience_years', 4, 1)->nullable()->after('trade');
            $table->date('joining_date')->nullable()->after('experience_years');
            $table->date('exit_date')->nullable()->after('joining_date');
            $table->string('project', 150)->nullable()->after('exit_date');
            $table->string('site', 150)->nullable()->after('project');
            $table->string('department', 120)->nullable()->after('site');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_workers', function (Blueprint $table) {
            $table->dropColumn([
                'blood_group', 'skill_category', 'trade',
                'emergency_contact', 'emergency_phone', 'age_reason',
                'card_status', 'card_issued_at', 'punch_count',
                'punch_1_at', 'punch_1_reason', 'punch_2_at', 'punch_2_reason',
                'punch_3_at', 'punch_3_reason',
                'bocw_number', 'experience_years', 'joining_date', 'exit_date',
                'project', 'site', 'department',
            ]);
        });
    }
};
