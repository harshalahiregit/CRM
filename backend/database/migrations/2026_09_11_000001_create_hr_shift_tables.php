<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift Management.
 *
 * `hr_employees.shift` and `hr_attendance.shift` are free-text strings today, and
 * they STAY that way — nothing here rewrites them. A shift assignment is the
 * structured source; the string columns keep working for records that predate it.
 *
 * Two modelling decisions worth stating, because the alternative in both cases is
 * the same data recorded twice:
 *
 *  - WEEKLY OFF is a property of a shift's weekday timing, not a separate table.
 *    A day is off when `is_week_off` is set, optionally only in the weeks listed in
 *    `week_numbers` (the alternate-Saturday pattern). One row per shift per weekday
 *    means "is Saturday off?" has exactly one answer.
 *
 *  - SHIFT ASSIGNMENT and SHIFT HISTORY are one table. An assignment is a row with
 *    `effective_to` null; history is the same row once superseded. A dedicated
 *    history table would duplicate every column and drift the moment one is edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Shift master ──────────────────────────────────────────────────
        Schema::create('hr_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            // Fixed = same timing every week. Rotational = driven by a rotation plan.
            // Flexible = hours tracked, no fixed start.
            $table->string('shift_type', 20)->default('Fixed');
            $table->boolean('is_night_shift')->default(false);
            // Minutes of lateness tolerated before a day counts as Late.
            $table->unsignedSmallInteger('grace_in_minutes')->default(0);
            $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            // Hours thresholds — a day below half_day_hours is Absent, below
            // full_day_hours is Half Day. Both configurable; nothing assumed.
            $table->decimal('full_day_hours', 5, 2)->nullable();
            $table->decimal('half_day_hours', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_shift_name_unique');
        });

        // ── Weekday timing + weekly off ───────────────────────────────────
        Schema::create('hr_shift_timings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('shift_id')->index();
            // 0 = Sunday … 6 = Saturday, matching Carbon::dayOfWeek so no mapping
            // table is needed anywhere that compares them.
            $table->unsignedTinyInteger('day_of_week');
            $table->string('start_time', 5)->nullable();   // "09:00"
            $table->string('end_time', 5)->nullable();     // "18:00" (may be < start on a night shift)
            $table->boolean('is_week_off')->default(false);
            // Which weeks of the month the off applies to, e.g. [2,4] for alternate
            // Saturdays. Null/empty = every week.
            $table->json('week_numbers')->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'day_of_week'], 'hr_shift_timing_unique');
        });

        // ── Rotation plans ────────────────────────────────────────────────
        Schema::create('hr_shift_rotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_rotation_name_unique');
        });

        Schema::create('hr_shift_rotation_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('rotation_id')->index();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedInteger('sequence')->default(0);
            // How long this step lasts before the next one starts. Days, so a plan
            // can rotate weekly (7), fortnightly (14) or on any other cycle.
            $table->unsignedInteger('duration_days')->default(7);
            $table->timestamps();

            $table->index(['rotation_id', 'sequence'], 'hr_rotation_step_idx');
        });

        // ── Assignment == history ─────────────────────────────────────────
        Schema::create('hr_employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            // Exactly one of these is set: a fixed shift, or a rotation plan.
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('rotation_id')->nullable();
            $table->date('effective_from');
            // NULL = currently in force. Set when a later assignment supersedes it,
            // which is what turns this row into history.
            $table->date('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'hr_emp_shift_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_shifts');
        Schema::dropIfExists('hr_shift_rotation_steps');
        Schema::dropIfExists('hr_shift_rotations');
        Schema::dropIfExists('hr_shift_timings');
        Schema::dropIfExists('hr_shifts');
    }
};
