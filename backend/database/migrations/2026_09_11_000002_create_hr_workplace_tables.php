<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workplace Management — Branch → Office → Floor, and who sits where.
 *
 * `hr_employees.location` (free text, a city) is left untouched. This is the
 * structured replacement; the old column keeps its meaning for existing rows.
 *
 * `hr_branches.work_state` deliberately uses the SAME canonical state vocabulary
 * as Professional Tax (App\Support\Hr\WorkStates). A branch is the natural place
 * for a jurisdiction to live, so a later phase can resolve PT from the employee's
 * branch instead of a per-employee field — without inventing a second state list.
 *
 * Work location assignment follows the same effective-dated shape as shift
 * assignment: current is `effective_to IS NULL`, history is the superseded rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            // Canonical state name — validated against WorkStates, so it can key
            // statutory rules directly.
            $table->string('work_state', 80)->nullable();
            $table->string('pincode', 20)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_branch_name_unique');
            $table->index(['tenant_id', 'work_state'], 'hr_branch_state_idx');
        });

        Schema::create('hr_offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Unique within a branch, not tenant-wide — two branches may each have
            // a "Ground Office" and that is not a conflict.
            $table->unique(['branch_id', 'name'], 'hr_office_name_unique');
        });

        Schema::create('hr_floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('office_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->unsignedInteger('seat_capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'name'], 'hr_floor_name_unique');
        });

        Schema::create('hr_employee_work_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->string('seat_no', 40)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();   // NULL = current
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'hr_emp_workloc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_work_locations');
        Schema::dropIfExists('hr_floors');
        Schema::dropIfExists('hr_offices');
        Schema::dropIfExists('hr_branches');
    }
};
