<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Task Statuses (configurable kanban columns) ─────────────
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#7C3AED');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_completed_status')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
        });

        // ── Tasks (polymorphic — attachable to any sales entity) ────
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            // taskable_type stores the FQCN (same convention as sales_line_items);
            // nullable = a standalone task not attached to any entity.
            $table->string('taskable_type')->nullable();
            $table->unsignedBigInteger('taskable_id')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('task_statuses')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('date_finished')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('is_billable')->default(false);
            $table->decimal('billable_amount', 12, 2)->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->unsignedInteger('recur_every')->nullable();
            $table->enum('recur_type', ['day', 'week', 'month', 'year'])->nullable();
            $table->unsignedInteger('recur_cycles')->nullable();
            $table->unsignedInteger('recur_total_cycles')->nullable();
            $table->date('last_recurred_date')->nullable();
            $table->unsignedInteger('kanban_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['taskable_type', 'taskable_id']);
            $table->index('status_id');
            $table->index('due_date');
            $table->index('kanban_order');
        });

        // ── Task Assignees (many-to-many staff) ─────────────────────
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'staff_id']);
            $table->index('tenant_id');
        });

        // ── Task Checklist Items ────────────────────────────────────
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('description');
            $table->boolean('is_finished')->default(false);
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('task_id');
        });

        // ── Task Comments ───────────────────────────────────────────
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->text('content');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_statuses');
    }
};
