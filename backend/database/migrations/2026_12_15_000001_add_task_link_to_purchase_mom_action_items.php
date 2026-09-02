<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a Purchase meeting action be pushed into the Task module.
 *
 * The shared engine can turn a MOM action into a real Task so it appears in
 * someone's task list instead of living only in the minutes; Purchase had no
 * link column, so the same action could only ever be chased by re-reading the
 * meeting. This is the one column that makes the push possible — and, being
 * unique per action, it is also what stops the same action being pushed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_mom_action_items', 'task_id')) {
            return;
        }

        Schema::table('purchase_mom_action_items', function (Blueprint $table) {
            // No FK constraint: tasks live in another module's table and a task
            // being deleted must not cascade into the meeting record — the
            // action stays, it simply stops pointing anywhere.
            $table->unsignedBigInteger('task_id')->nullable()->after('evidence_path');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_mom_action_items', 'task_id')) {
            return;
        }

        Schema::table('purchase_mom_action_items', function (Blueprint $table) {
            $table->dropIndex(['task_id']);
            $table->dropColumn('task_id');
        });
    }
};
