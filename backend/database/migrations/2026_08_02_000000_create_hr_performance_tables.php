<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance Management System (PMS).
 *
 * Reuses existing masters — hr_employees, hr_departments, hr_designations,
 * hr_employee_salaries (read-only, for the increment recommendation snapshot).
 * Adds only the tables PMS needs. All tenant-scoped, `hr_*` convention. No
 * attendance data is stored or computed here.
 */
return new class extends Migration {
    public function up(): void
    {
        // KPI master (Phase 3)
        Schema::create('hr_kpis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('weightage', 6, 2)->default(0);   // % weight
            $table->unsignedTinyInteger('rating_scale')->default(5); // e.g. 1–5
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Goal / KRA definitions (Phase 2)
        Schema::create('hr_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->decimal('weightage', 6, 2)->default(0);
            $table->string('target')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('Active');  // Active | Completed | Archived
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Goal → employee assignments (Phase 2)
        Schema::create('hr_employee_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('goal_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('status')->default('Active');    // Active | Completed
            $table->unsignedTinyInteger('progress')->default(0); // 0–100
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'goal_id', 'employee_id']);
        });

        // Performance reviews (Phase 4)
        Schema::create('hr_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('reviewer_name')->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('review_type');                  // Monthly | Quarterly | Half-Yearly | Annual
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->string('period_label')->nullable();
            $table->decimal('overall_rating', 4, 2)->default(0);
            $table->text('comments')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('status')->default('Draft');     // Draft | Submitted | Reviewed | Approved
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // Per-review KPI ratings (Phase 4) — snapshots the KPI name + weightage.
        Schema::create('hr_performance_review_kpis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('review_id')->index();
            $table->unsignedBigInteger('kpi_id')->nullable();
            $table->string('kpi_name');
            $table->decimal('weightage', 6, 2)->default(0);
            $table->decimal('rating', 4, 2)->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        // Promotion recommendations (Phase 5)
        Schema::create('hr_promotion_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->boolean('eligible')->default(false);
            $table->decimal('overall_rating', 4, 2)->default(0);
            $table->unsignedInteger('completed_goals')->default(0);
            $table->string('current_designation')->nullable();
            $table->string('recommended_designation')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('Pending');   // Pending | Approved | Rejected
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Increment recommendations (Phase 6) — recommendation only, never touches Payroll.
        Schema::create('hr_increment_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->decimal('current_salary', 14, 2)->default(0);   // snapshot from hr_employee_salaries
            $table->decimal('suggested_percentage', 6, 2)->default(0);
            $table->decimal('suggested_amount', 14, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('approval_status')->default('Pending');  // Pending | Approved | Rejected
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_increment_recommendations');
        Schema::dropIfExists('hr_promotion_recommendations');
        Schema::dropIfExists('hr_performance_review_kpis');
        Schema::dropIfExists('hr_performance_reviews');
        Schema::dropIfExists('hr_employee_goals');
        Schema::dropIfExists('hr_goals');
        Schema::dropIfExists('hr_kpis');
    }
};
