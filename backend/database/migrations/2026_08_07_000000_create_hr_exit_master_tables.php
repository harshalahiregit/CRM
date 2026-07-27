<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit / Separation Management — Phase 1 (Exit Types & Exit Policies).
 *
 * Two tenant-scoped master tables. Exit Policies reuse Organization Setup
 * (hr_grades / hr_designations / hr_departments) via nullable references and
 * point at a default Exit Type — no duplicated masters. Uniqueness (name/code)
 * is DB-enforced per tenant. Never hard-deleted (deactivate to retire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_exit_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('notice_required')->default(true);
            $table->unsignedSmallInteger('default_notice_days')->default(0);
            $table->boolean('clearance_required')->default(true);
            $table->boolean('fnf_required')->default(true);
            $table->boolean('exit_interview_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_exit_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('grade_id')->nullable();        // Organization Setup
            $table->unsignedBigInteger('designation_id')->nullable();  // Organization Setup
            $table->unsignedBigInteger('department_id')->nullable();   // Organization Setup
            $table->unsignedBigInteger('default_exit_type_id')->nullable(); // hr_exit_types
            $table->unsignedSmallInteger('notice_days')->default(0);
            $table->boolean('buyout_allowed')->default(false);
            $table->boolean('recovery_allowed')->default(false);
            $table->boolean('leave_encashment')->default(false);
            $table->boolean('gratuity_applicable')->default(false);
            $table->boolean('clearance_required')->default(true);
            $table->boolean('exit_interview_required')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_policies');
        Schema::dropIfExists('hr_exit_types');
    }
};
