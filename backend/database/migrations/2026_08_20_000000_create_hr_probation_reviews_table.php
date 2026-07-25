<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation Management — Phase 3 (Probation Reviews).
 *
 * One tenant-scoped table: a periodic review on an employee probation
 * (hr_employee_probations). Reuses hr_employees for employee + reviewer — no
 * duplicated data. Ratings are 1-5; recommendation drives future workflow only.
 * Lifecycle: Draft → Submitted → Completed. Never hard-deleted. One review number
 * per probation (DB-enforced).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_probation_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_probation_id')->index(); // hr_employee_probations
            $table->unsignedBigInteger('employee_id')->index();           // hr_employees
            $table->unsignedSmallInteger('review_no')->default(1);
            $table->date('review_date');
            $table->unsignedBigInteger('reviewer_id')->nullable();        // hr_employees (reviewing manager)

            $table->unsignedTinyInteger('overall_rating')->default(0);
            $table->unsignedTinyInteger('technical_rating')->default(0);
            $table->unsignedTinyInteger('behaviour_rating')->default(0);
            $table->unsignedTinyInteger('attendance_rating')->default(0);
            $table->unsignedTinyInteger('communication_rating')->default(0);

            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('manager_comments')->nullable();
            $table->text('hr_comments')->nullable();
            $table->string('recommendation');                             // Continue | Extend | Confirm | Fail
            $table->string('status')->default('Draft')->index();          // Draft | Submitted | Completed

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_probation_id', 'review_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_probation_reviews');
    }
};
