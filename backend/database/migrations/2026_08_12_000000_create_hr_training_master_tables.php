<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning & Development — Phase 1 (Training Masters).
 *
 * Three tenant-scoped master tables that later L&D phases (Programs, Sessions,
 * Assignments…) reference. Uniqueness (name/code) is DB-enforced per tenant.
 * Never hard-deleted — deactivate to retire (soft-deactivate via is_active).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_training_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_training_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->string('mode')->default('Offline');          // Online | Offline | Hybrid
            $table->unsignedSmallInteger('default_duration_hours')->default(0);
            $table->boolean('certification_applicable')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_training_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('provider_type')->default('External'); // Internal | External
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
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
        Schema::dropIfExists('hr_training_providers');
        Schema::dropIfExists('hr_training_types');
        Schema::dropIfExists('hr_training_categories');
    }
};
