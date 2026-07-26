<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task module — polymorphic work unit (owner: Shivam). SQLite-safe.
 *
 * priority values match Helpdesk exactly (low/medium/high/urgent) as strings.
 * rel_type/rel_id are polymorphic (project|ticket|customer|standalone) — rel_id
 * has NO foreign key; customer links resolve via CustomerServiceContract (mock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();                 // HTML
            $table->string('priority', 20)->default('medium');       // low|medium|high|urgent (matches Helpdesk)
            $table->string('status', 30)->default('not_started');    // not_started|in_progress|awaiting_feedback|testing|complete

            $table->date('start_date');
            $table->date('due_date')->nullable();
            $table->timestamp('date_finished')->nullable();

            $table->string('rel_type', 20)->default('standalone');   // project|ticket|customer|standalone
            $table->unsignedBigInteger('rel_id')->nullable();        // polymorphic — no FK
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();

            $table->boolean('billable')->default(false);
            $table->boolean('billed')->default(false);
            $table->decimal('hourly_rate', 15, 2)->nullable();

            $table->boolean('is_public')->default(false);            // visible to all staff
            $table->boolean('visible_to_client')->default(false);
            $table->unsignedInteger('kanban_order')->default(0);

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('priority');
            $table->index(['rel_type', 'rel_id']);
            $table->index('milestone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
