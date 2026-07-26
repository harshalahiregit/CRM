<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning & Development — Phase 5 (Training Attendance + Assessment + Quiz).
 *
 * Three tenant-scoped tables hanging off an Employee Training Assignment
 * (hr_employee_trainings). Training attendance is entirely separate from office
 * attendance / SangoeTrack. No masters or employee data duplicated. Percentages /
 * results are computed in the service, never editable directly.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_training_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('training_session_id');         // hr_training_sessions
            $table->unsignedBigInteger('employee_training_id');        // hr_employee_trainings
            $table->unsignedBigInteger('employee_id');                 // hr_employees
            $table->string('attendance_status')->default('Present');   // Present | Absent
            $table->dateTime('check_in')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_training_id']);     // one attendance per assignment
            $table->index(['tenant_id', 'training_session_id']);
        });

        Schema::create('hr_training_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_training_id')->index();
            $table->string('assessment_name');
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('obtained_marks', 8, 2)->default(0);
            $table->decimal('passing_marks', 8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->string('result')->default('Fail');                 // Pass | Fail
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_training_quizzes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_training_id')->index();
            $table->string('quiz_name');
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('obtained_marks', 8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_training_quizzes');
        Schema::dropIfExists('hr_training_assessments');
        Schema::dropIfExists('hr_training_attendance');
    }
};
