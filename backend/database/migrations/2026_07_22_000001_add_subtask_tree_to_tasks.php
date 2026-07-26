<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns tasks into a tree: Website -> Design -> Homepage mockup -> ...
 *
 * Three columns rather than the obvious one:
 *
 *  • parent_id — the actual structure. Everything else is derived from it.
 *  • root_id   — the top ancestor, carried on every node. This is the whole
 *    performance story: loading a tree becomes ONE query (where root_id = X)
 *    assembled in PHP, instead of a query per level or a recursive CTE that
 *    behaves differently on SQLite and MySQL.
 *  • depth     — 0 for a top-level task. Lets us cap runaway nesting and order a
 *    tree for display without walking it first.
 *
 * Existing rows are top-level tasks, so they get parent_id null, depth 0, and
 * root_id = their own id — which is exactly what a one-node tree means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('tenant_id');
            $table->unsignedBigInteger('root_id')->nullable()->after('parent_id');
            $table->unsignedTinyInteger('depth')->default(0)->after('root_id');

            // Children-of and whole-tree-of are the only two shapes we ever query.
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'root_id']);
        });

        // Every task that exists today is the root of its own single-node tree.
        DB::table('tasks')->whereNull('root_id')->update(['root_id' => DB::raw('id'), 'depth' => 0]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'parent_id']);
            $table->dropIndex(['tenant_id', 'root_id']);
            $table->dropColumn(['parent_id', 'root_id', 'depth']);
        });
    }
};
