<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — more than one person on an issue.
 *
 * `sire_reports.assignee_id` STAYS, and stays the owner. Every workflow guard is
 * written against it -- start_development checks the actor IS the assignee,
 * qa_failed notifies them, the developer queue is filtered by them -- and a
 * defect with four equal owners has none. So this table holds the OTHERS: people
 * who are also working it and should hear about it, alongside one person who is
 * answerable for it.
 *
 * That distinction is the whole design. "Assign to several" usually means "this
 * needs a backend and a frontend change", not "nobody in particular owns this",
 * and collapsing assignee_id into a list would have rewritten twenty guards to
 * answer a question nobody asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_report_assignees')) {
            return;
        }

        Schema::create('sire_report_assignees', function (Blueprint $table) {
            $table->id();

            // NOT NULL like every other SIRE table: the auto-stamp does nothing
            // in a command or a job, and a nullable column absorbs that mistake
            // into rows that belong to nobody.
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('user_id');

            // Who added them, so "why is this on my list" has an answer.
            $table->unsignedBigInteger('added_by')->nullable();

            $table->timestamps();

            // One row per person per issue; assigning twice is assigning once,
            // and the database is the only place that can promise it under a race.
            $table->unique(['tenant_id', 'report_id', 'user_id'], 'sire_assignee_unique');
            $table->index(['tenant_id', 'report_id'], 'sire_assignee_report');
            $table->index(['tenant_id', 'user_id'], 'sire_assignee_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_report_assignees');
    }
};
