<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning & Development — Phase 4 (Employee Training Assignment).
 *
 * One tenant-scoped table linking an employee to a Training Program instance
 * (Session). Reuses hr_employees / hr_training_programs / hr_training_sessions —
 * all referenced, none duplicated. Lifecycle: Assigned → In Progress → Completed,
 * with Cancelled before completion. Never hard-deleted (cancel to retire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();        // hr_employees
            $table->unsignedBigInteger('training_program_id');         // hr_training_programs
            $table->unsignedBigInteger('training_session_id');         // hr_training_sessions
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('Assigned')->index();    // Assigned | In Progress | Completed | Cancelled
            $table->text('remarks')->nullable();
            $table->unsignedTinyInteger('completion_percentage')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'training_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_trainings');
    }
};
