<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable Task/Project statuses — the spec's "Advanced Status Manager".
 * (owner: Shivam)
 *
 * `key` is separate from `name` on purpose. Helpdesk's ticket_statuses stores the
 * NAME as the value in tickets.status, which means renaming a status there
 * silently orphans every ticket holding the old name. Here tasks.status /
 * projects.status keep their immutable key ('not_started'), and `name` is just
 * the label — so "Not Started" can be renamed to "Backlog" without touching a row.
 *
 * is_system marks the statuses code depends on: TaskService keys date_finished
 * off 'complete', and ProjectService::progress counts 'complete' tasks and stamps
 * date_finished on 'finished'. Those can be renamed and recoloured but never
 * deleted, or that logic breaks silently.
 *
 * can_be_changed_to is a JSON array of keys — an empty/null value means "no
 * restriction", which is what every seeded row uses, so this migration changes no
 * existing behaviour until someone actually configures a workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['task_statuses', 'project_statuses'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $t->string('key', 40);                  // stored in tasks.status / projects.status
                $t->string('name', 60);                 // label only — safe to rename
                $t->string('color', 9)->default('#94a3b8');
                $t->unsignedInteger('order')->default(0);
                $t->boolean('is_default_filter')->default(false);
                $t->boolean('is_closed_status')->default(false);   // 'complete' / 'finished'
                $t->boolean('is_system')->default(false);          // cannot be deleted
                $t->json('can_be_changed_to')->nullable();         // null = no restriction
                $t->json('hidden_for')->nullable();                // roles that don't see it
                $t->timestamps();

                $t->unique(['tenant_id', 'key']);
                $t->index('tenant_id');
            });
        }

        // Seed every existing tenant with the current hardcoded defaults, so the
        // lookup tables describe exactly today's behaviour on day one.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $this->seedFor($tenantId);
        }
    }

    private function seedFor(int $tenantId): void
    {
        $now = now();

        $tasks = [
            ['not_started',       'Not Started',       '#64748b', 1,   false],
            ['in_progress',       'In Progress',       '#3b82f6', 2,   false],
            ['testing',           'Testing',           '#0284c7', 3,   false],
            ['awaiting_feedback', 'Awaiting Feedback', '#84cc16', 4,   false],
            ['complete',          'Complete',          '#22c55e', 100, true],
        ];
        DB::table('task_statuses')->insert(array_map(fn ($r) => [
            'tenant_id' => $tenantId, 'key' => $r[0], 'name' => $r[1], 'color' => $r[2],
            'order' => $r[3], 'is_closed_status' => $r[4],
            // Every seeded status is system: these five are the enum the rest of
            // the module validates against, so deleting one would reject existing rows.
            'is_system' => true, 'is_default_filter' => false,
            'can_be_changed_to' => null, 'hidden_for' => null,
            'created_at' => $now, 'updated_at' => $now,
        ], $tasks));

        $projects = [
            ['not_started', 'Not Started', '#475569', 1,   false],
            ['in_progress', 'In Progress', '#2563eb', 2,   false],
            ['on_hold',     'On Hold',     '#f97316', 3,   false],
            ['cancelled',   'Cancelled',   '#94a3b8', 4,   true],
            ['finished',    'Finished',    '#16a34a', 100, true],
        ];
        DB::table('project_statuses')->insert(array_map(fn ($r) => [
            'tenant_id' => $tenantId, 'key' => $r[0], 'name' => $r[1], 'color' => $r[2],
            'order' => $r[3], 'is_closed_status' => $r[4],
            'is_system' => true, 'is_default_filter' => false,
            'can_be_changed_to' => null, 'hidden_for' => null,
            'created_at' => $now, 'updated_at' => $now,
        ], $projects));
    }

    public function down(): void
    {
        Schema::dropIfExists('project_statuses');
        Schema::dropIfExists('task_statuses');
    }
};
