<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional "Related To" links for a task. A task keeps its ONE primary link on
 * tasks.rel_type/rel_id (that drives milestone rules, filters and the project
 * task list — untouched). This side table lets the same task ALSO relate to any
 * number of other things (a project + a ticket + a lead + a meeting…), which is
 * the doc's "a task can be related to many things".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_relations')) {
            return;
        }
        Schema::create('task_relations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $t->string('rel_type', 40);              // project|ticket|customer|contract|tpv_vendor|purchase_vendor|lead|meeting
            $t->unsignedBigInteger('rel_id');
            $t->timestamps();

            // One task can't hold the same link twice; also makes sync insertOrIgnore-safe.
            $t->unique(['task_id', 'rel_type', 'rel_id']);
            $t->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_relations');
    }
};
