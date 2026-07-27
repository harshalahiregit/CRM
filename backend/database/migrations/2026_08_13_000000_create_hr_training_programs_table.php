<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning & Development — Phase 2 (Training Programs).
 *
 * One tenant-scoped table built on the Phase 1 masters (category / type /
 * provider) and, optionally, Organization Setup (department / designation) — all
 * referenced, none duplicated. Uniqueness (code/name) is DB-enforced per tenant.
 * Never hard-deleted — deactivate to retire (is_active).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_training_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('category_id');                 // hr_training_categories
            $table->unsignedBigInteger('training_type_id');            // hr_training_types
            $table->unsignedBigInteger('provider_id');                 // hr_training_providers
            $table->unsignedBigInteger('department_id')->nullable();   // Organization Setup
            $table->unsignedBigInteger('designation_id')->nullable();  // Organization Setup

            $table->string('program_code');
            $table->string('program_name');
            $table->text('description')->nullable();
            $table->text('objectives')->nullable();
            $table->unsignedInteger('duration')->default(1);
            $table->string('duration_unit')->default('Hours');         // Hours | Days | Weeks
            $table->string('mode')->default('Offline');                // Online | Offline | Hybrid
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('certification_applicable')->default(false);
            $table->unsignedTinyInteger('passing_percentage')->default(0);
            $table->unsignedInteger('validity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'program_code']);
            $table->unique(['tenant_id', 'program_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_training_programs');
    }
};
