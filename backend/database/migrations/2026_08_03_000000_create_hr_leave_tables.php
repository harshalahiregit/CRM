<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave Management — Phase 1 (Foundation).
 *
 * Leave Types master, Leave Policies, and Policy ↔ Type mapping only. Reuses
 * Organization Setup grade/designation (nullable references, no duplication).
 * Balance / application / approval / holiday calendar / reports are future phases —
 * no tables for those here. All tenant-scoped, `hr_*` convention.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->string('category');                      // Casual | Sick | Earned | Maternity | Paternity | Restricted | Unpaid
            $table->boolean('paid')->default(true);
            $table->decimal('yearly_limit', 6, 1)->default(0);
            $table->boolean('carry_forward')->default(false);
            $table->decimal('max_carry_forward', 6, 1)->default(0);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('applies_to')->default('All');    // All | Grade | Designation
            $table->unsignedBigInteger('grade_id')->nullable();        // Organization Setup → hr_grades
            $table->unsignedBigInteger('designation_id')->nullable();  // Organization Setup → hr_designations
            $table->boolean('probation_allowed')->default(false);
            $table->boolean('notice_period_allowed')->default(false);
            $table->boolean('weekends_count')->default(false);   // weekends counted within a leave span
            $table->boolean('holidays_count')->default(false);   // holidays counted within a leave span
            $table->boolean('half_day_allowed')->default(true);
            $table->boolean('negative_balance_allowed')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_leave_policy_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('leave_type_id');
            $table->decimal('yearly_allocation', 6, 1)->default(0);
            $table->decimal('carry_forward_limit', 6, 1)->default(0);
            $table->timestamps();

            $table->unique(['policy_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_policy_types');
        Schema::dropIfExists('hr_leave_policies');
        Schema::dropIfExists('hr_leave_types');
    }
};
