<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Phase 2 — Salary Structures.
 *
 * A named, tenant-scoped structure (e.g. "Software Engineer Grade A") that
 * composes existing Salary Components (Phase 1) into a computed CTC breakdown.
 * Each line references a component and carries the structure-specific value
 * (a fixed amount, or a percentage of another line such as Basic). No employee
 * assignment or payroll processing here — structure definition only.
 *
 * Reuses Organization Setup masters via nullable grade_id / designation_id.
 * Lines are never orphaned: they cascade with their parent structure only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();        // Organization Setup → hr_grades
            $table->unsignedBigInteger('designation_id')->nullable();  // Organization Setup → hr_designations
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hr_salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('structure_id')->index();
            $table->unsignedBigInteger('component_id');   // hr_salary_components
            $table->decimal('amount', 12, 2)->nullable();      // for Fixed components
            $table->decimal('percentage', 5, 2)->nullable();   // for Percentage components
            $table->string('based_on')->nullable();            // component name this % resolves against (e.g. "Basic Salary")
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_structure_lines');
        Schema::dropIfExists('hr_salary_structures');
    }
};
