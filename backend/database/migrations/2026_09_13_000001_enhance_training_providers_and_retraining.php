<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comments #22 and #23. Both are additive to tables that already exist —
 * no data is moved and nothing is dropped.
 *
 * #22 — Training Provider: department, expertise, certification, qualification,
 *   skills.
 *   `department_id` points at the EXISTING hr_departments master rather than a
 *   provider-specific department list: a provider aligned to "Engineering" means
 *   the same Engineering the employees sit in, and a second list would drift.
 *   The other four are free-text arrays. There is no company-wide master for
 *   expertise or qualifications, and inventing three new master tables for values
 *   nobody has asked to manage centrally would be scope nobody wants.
 *   `skills` deliberately matches the shape used by hr_designations.skills and
 *   hr_employees.skills, so SkillMatcher reads all three the same way.
 *
 * #23 — Retraining: an employee assigned the SAME programme again.
 *   No new table. The second assignment is just another hr_employee_trainings row
 *   — a separate "retraining" table would duplicate every column of it and split
 *   an employee's history in two. `attempt_number` says which go this is, and
 *   `previous_training_id` chains them so the history reads in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── #22 ───────────────────────────────────────────────────────────
        Schema::table('hr_training_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_training_providers', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable();   // → hr_departments
            }
            foreach (['expertise', 'certifications', 'qualifications', 'skills'] as $col) {
                if (! Schema::hasColumn('hr_training_providers', $col)) {
                    $table->json($col)->nullable();
                }
            }
        });

        // ── #23 ───────────────────────────────────────────────────────────
        Schema::table('hr_employee_trainings', function (Blueprint $table) {
            // 1 = first time. Existing rows default to 1, which is correct: every
            // assignment written before today was somebody's first recorded go.
            if (! Schema::hasColumn('hr_employee_trainings', 'attempt_number')) {
                $table->unsignedSmallInteger('attempt_number')->default(1);
            }
            // Denormalised from attempt_number > 1 so listings can filter without
            // arithmetic. Written by the service, never by hand.
            if (! Schema::hasColumn('hr_employee_trainings', 'is_retraining')) {
                $table->boolean('is_retraining')->default(false);
            }
            if (! Schema::hasColumn('hr_employee_trainings', 'previous_training_id')) {
                $table->unsignedBigInteger('previous_training_id')->nullable();
            }
            // Why they are doing it again — the question anyone reading a
            // retraining record asks first.
            if (! Schema::hasColumn('hr_employee_trainings', 'retraining_reason')) {
                $table->string('retraining_reason', 500)->nullable();
            }
        });

        Schema::table('hr_employee_trainings', function (Blueprint $table) {
            $table->index(['tenant_id', 'employee_id', 'training_program_id'], 'hr_emp_train_attempt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_trainings', function (Blueprint $table) {
            $table->dropIndex('hr_emp_train_attempt_idx');
        });
    }
};
