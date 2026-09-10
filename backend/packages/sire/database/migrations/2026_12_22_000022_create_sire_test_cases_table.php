<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — test cases attached to an issue.
 *
 * A CORE table, not an AI one. Test cases are part of the QA workflow: people
 * write them, people run them, people record results. Generation is one way a
 * draft gets here — the same table serves a test typed by hand, and nothing about
 * a row says it must have come from a generator except its `source`.
 *
 * `result` is the column this whole feature is careful about. It defaults to NULL
 * and is only ever written by SireTestCaseService::record(), which requires a human
 * actor. The AI layer has no path to it, and the module-boundary test enforces
 * that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_test_cases')) {
            return;
        }

        Schema::create('sire_test_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');   // NOT NULL by design
            $table->unsignedBigInteger('report_id');

            // happy_path | failure_path | boundary | permission | regression | related_workflow
            $table->string('category', 24);
            $table->string('title', 255);

            // Given / when / then, kept as three columns rather than one blob:
            // QA reads them separately and a generator fills them separately.
            $table->text('given')->nullable();
            $table->text('when')->nullable();
            $table->text('then')->nullable();

            // Why this test exists. Carried through from generation so a reader can
            // disagree with the reason, not just the test.
            $table->string('rationale', 1000)->nullable();

            // ai_suggested | human
            $table->string('source', 16)->default('human');
            // The suggestion a generated test came from, so accept/reject feedback
            // can be traced back to what produced it. Logical link, no FK.
            $table->unsignedBigInteger('ai_suggestion_id')->nullable();

            // developer | qa | both — who is expected to run it
            $table->string('phase', 16)->default('both');

            // draft | active | removed
            $table->string('status', 16)->default('active');

            /*
             * NULL until a human runs it. Never written by generation, never
             * defaulted to anything else. An AI-generated test that arrived
             * "passed" would be worse than no test at all.
             */
            $table->string('result', 16)->nullable();  // passed | failed | blocked | skipped
            $table->unsignedBigInteger('executed_by')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->text('result_note')->nullable();
            // Which QA cycle recorded it, so a re-run after a failure is legible.
            $table->unsignedBigInteger('work_cycle_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'report_id', 'status'], 'sire_tc_report_idx');
            $table->index(['tenant_id', 'result'], 'sire_tc_result_idx');
            $table->index(['tenant_id', 'ai_suggestion_id'], 'sire_tc_suggestion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_test_cases');
    }
};
