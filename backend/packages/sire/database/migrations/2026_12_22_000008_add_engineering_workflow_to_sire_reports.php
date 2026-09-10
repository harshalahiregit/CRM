<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — Phase 1 engineering workflow columns.
 *
 * Column-by-column guarded rather than table-guarded, so this is safe to run
 * whether or not the create migration already carries some of these. Live has
 * repeatedly been found with migrations pending from earlier deploys; an
 * all-or-nothing guard would skip the whole file after one column landed.
 */
return new class extends Migration
{
    /** column => closure that adds it */
    private function columns(): array
    {
        return [
            // --- reproduction, what the developer and QA both need -----------
            'steps_to_reproduce'      => fn (Blueprint $t) => $t->text('steps_to_reproduce')->nullable(),
            'expected_result'         => fn (Blueprint $t) => $t->text('expected_result')->nullable(),
            'actual_result'           => fn (Blueprint $t) => $t->text('actual_result')->nullable(),

            // --- scheduling: severity is impact, priority is when ------------
            'priority'                => fn (Blueprint $t) => $t->string('priority', 8)->nullable(),

            // --- assignment ---------------------------------------------------
            'assigned_at'             => fn (Blueprint $t) => $t->dateTime('assigned_at')->nullable(),
            'assignment_accepted_at'  => fn (Blueprint $t) => $t->dateTime('assignment_accepted_at')->nullable(),
            'qa_assignee_id'          => fn (Blueprint $t) => $t->unsignedBigInteger('qa_assignee_id')->nullable(),

            // --- development --------------------------------------------------
            'development_started_at'  => fn (Blueprint $t) => $t->dateTime('development_started_at')->nullable(),
            'investigation_notes'     => fn (Blueprint $t) => $t->text('investigation_notes')->nullable(),
            'fix_summary'             => fn (Blueprint $t) => $t->text('fix_summary')->nullable(),
            'dev_test_notes'          => fn (Blueprint $t) => $t->text('dev_test_notes')->nullable(),
            'ready_for_qa_at'         => fn (Blueprint $t) => $t->dateTime('ready_for_qa_at')->nullable(),

            // --- qa -------------------------------------------------------------
            'qa_started_at'           => fn (Blueprint $t) => $t->dateTime('qa_started_at')->nullable(),
            'qa_notes'                => fn (Blueprint $t) => $t->text('qa_notes')->nullable(),

            // --- release --------------------------------------------------------
            'release_ref'             => fn (Blueprint $t) => $t->string('release_ref', 120)->nullable(),
            'released_at'             => fn (Blueprint $t) => $t->dateTime('released_at')->nullable(),
            'released_by'             => fn (Blueprint $t) => $t->unsignedBigInteger('released_by')->nullable(),
            'production_validated_at' => fn (Blueprint $t) => $t->dateTime('production_validated_at')->nullable(),
            'validated_by'            => fn (Blueprint $t) => $t->unsignedBigInteger('validated_by')->nullable(),

            // --- pause / stop ---------------------------------------------------
            'held_from_status'        => fn (Blueprint $t) => $t->string('held_from_status', 32)->nullable(),
            'held_at'                 => fn (Blueprint $t) => $t->dateTime('held_at')->nullable(),
            'hold_reason'             => fn (Blueprint $t) => $t->string('hold_reason', 500)->nullable(),
            'resolution'              => fn (Blueprint $t) => $t->string('resolution', 24)->nullable(),
            'resolution_note'         => fn (Blueprint $t) => $t->string('resolution_note', 2000)->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('sire_reports')) {
            return;
        }

        foreach ($this->columns() as $name => $add) {
            if (Schema::hasColumn('sire_reports', $name)) {
                continue;
            }
            Schema::table('sire_reports', function (Blueprint $table) use ($add) {
                $add($table);
            });
        }

        // Named short, because MySQL caps identifiers at 64 characters and the
        // SQLite test suite will not catch an overrun.
        Schema::table('sire_reports', function (Blueprint $table) {
            $table->index(['tenant_id', 'assignee_id', 'status'], 'sire_rep_dev_queue_idx');
            $table->index(['tenant_id', 'qa_assignee_id', 'status'], 'sire_rep_qa_queue_idx');
            $table->index(['tenant_id', 'priority'], 'sire_rep_tenant_priority_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sire_reports')) {
            return;
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            foreach (['sire_rep_dev_queue_idx', 'sire_rep_qa_queue_idx', 'sire_rep_tenant_priority_idx'] as $index) {
                $table->dropIndex($index);
            }
            $table->dropColumn(array_keys($this->columns()));
        });
    }
};
