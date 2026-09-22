<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A checklist line can be on more than one person.
 *
 * task_checklist_items.assigned_to is a single nullable FK, so "Priya and Rohit
 * are doing this one" had to be written as two separate lines, or as one line
 * with one name on it and the other person told verbally. Both happen, and both
 * lose the second person the moment anybody looks at the list.
 *
 * ── assigned_to is kept, as a mirror ──────────────────────────────────────
 * It is not dropped and the column is not left to rot either: the service keeps
 * it pointing at the FIRST person in the set. Two reasons.
 *
 *  • The notification leg, the "assigned to me" filters and anything outside
 *    this module that reads the column keep working unchanged, instead of
 *    silently seeing null on every multi-assigned line.
 *  • Rolling back this migration leaves usable data rather than a table full of
 *    unowned checklist items.
 *
 * The pivot is the truth; the column is a cache of one row of it. Nothing may
 * write the column directly — TaskService::assignChecklistItem() owns both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_item_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('task_checklist_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // One person, one row. A second click is a no-op, not a duplicate.
            $table->unique(['item_id', 'user_id']);
            // "What is on my plate" reads this way round.
            $table->index(['tenant_id', 'user_id']);
        });

        // Everything already assigned moves across, so nobody loses an item the
        // day this ships.
        $now = now();
        DB::table('task_checklist_items')
            ->whereNotNull('assigned_to')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now) {
                DB::table('task_checklist_item_assignees')->insertOrIgnore(
                    collect($rows)->map(fn ($r) => [
                        'tenant_id'  => $r->tenant_id,
                        'item_id'    => $r->id,
                        'user_id'    => $r->assigned_to,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_item_assignees');
    }
};
