<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning & Development — Phase 3 (Training Sessions & Calendar).
 *
 * One tenant-scoped table: a scheduled instance of a Training Program (Phase 2).
 * Reuses Programs / Providers and, optionally, Organization Setup (department /
 * designation) — all referenced, none duplicated. No employee assignment yet
 * (Phase 4). Lifecycle: Scheduled → Ongoing → Completed, with Cancelled from
 * either. Never hard-deleted (cancel to retire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_training_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('training_program_id');         // hr_training_programs
            $table->unsignedBigInteger('provider_id')->nullable();     // hr_training_providers (defaults from program)
            $table->unsignedBigInteger('department_id')->nullable();   // Organization Setup
            $table->unsignedBigInteger('designation_id')->nullable();  // Organization Setup

            $table->string('title')->nullable();
            $table->string('trainer_name');
            $table->string('mode')->default('Offline');                // Online | Offline | Hybrid
            $table->string('venue')->nullable();
            $table->string('meeting_url')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedInteger('capacity')->default(1);
            $table->string('status')->default('Scheduled')->index();   // Scheduled | Ongoing | Completed | Cancelled
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_training_sessions');
    }
};
