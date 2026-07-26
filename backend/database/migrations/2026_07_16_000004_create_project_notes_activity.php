<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project notes + activity log (owner: Shivam) — the Notes and Activity tabs.
 *
 * Timesheets need no table: they aggregate the task_timers that already exist for
 * this project's tasks, so the Timesheets tab is a read-only view over data the
 * Task module owns rather than a duplicate store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('content')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('type', 60);                 // status_changed | member_added | note_added | …
            $table->string('description', 500);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('visible_to_customer')->default(false);
            $table->timestamp('created_at')->nullable(); // log rows are append-only; no updated_at

            $table->index(['tenant_id', 'project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
        Schema::dropIfExists('project_notes');
    }
};
