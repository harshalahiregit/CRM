<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation Management — Phase 1 (Masters & Policies).
 *
 * Two tenant-scoped master tables. Probation Policies reuse Organization Setup
 * (hr_grades / hr_designations / hr_departments) via nullable references and
 * point at a mandatory Probation Type — no duplicated masters. Uniqueness
 * (name/code) is DB-enforced per tenant. Never hard-deleted (deactivate to retire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_probation_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code');
            $table->string('name');
            $table->unsignedSmallInteger('default_duration_days')->default(90);
            $table->boolean('confirmation_required')->default(true);
            $table->boolean('review_required')->default(true);
            $table->boolean('extension_allowed')->default(true);
            $table->unsignedSmallInteger('max_extensions')->default(1);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_probation_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('probation_type_id');           // hr_probation_types (mandatory)
            $table->unsignedBigInteger('department_id')->nullable();   // Organization Setup
            $table->unsignedBigInteger('designation_id')->nullable();  // Organization Setup
            $table->unsignedBigInteger('grade_id')->nullable();        // Organization Setup
            $table->string('review_frequency')->default('Monthly');    // Weekly | Monthly | Quarterly
            $table->unsignedSmallInteger('notice_days')->default(0);
            $table->unsignedSmallInteger('extension_limit')->default(1);
            $table->boolean('auto_confirmation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_probation_policies');
        Schema::dropIfExists('hr_probation_types');
    }
};
