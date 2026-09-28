<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a record be approved more than once, over its life.
 *
 * Phase 1 put a unique index on (subject_type, subject_id) to mean "one live
 * approval per record". That reads correctly for leave and for loans, where a
 * decision is final and the record never returns to the queue.
 *
 * Variable earnings are not like that. VariableEarningService::save() resets an
 * edited earning to Pending and clears its approval — "editing the figure
 * invalidates the approval it was granted under" — so a record legitimately
 * comes back for a second decision on a different amount. Under the unique
 * index the engine would hand back the CLOSED request from the first round,
 * mayAct() would refuse it because it is not open, and an edited earning would
 * be pending and permanently unapprovable.
 *
 * So one row per ROUND rather than one per record. The history of what was
 * decided the first time survives untouched, which is the point: an auditor can
 * see that ₹5,000 was approved, the figure was changed, and ₹7,000 was approved
 * separately. Collapsing that into one row would have lost it.
 *
 * "One OPEN request per record" is now enforced in ApprovalEngine::requestFor(),
 * which looks for an open row and opens a new one only when there is none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_approval_requests', function (Blueprint $table) {
            $table->dropUnique(['subject_type', 'subject_id']);

            // The lookup that replaces it: find this record's open round.
            $table->index(['subject_type', 'subject_id', 'state'], 'hr_apvl_req_subject_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_approval_requests', function (Blueprint $table) {
            $table->dropIndex('hr_apvl_req_subject_state_idx');
            $table->unique(['subject_type', 'subject_id']);
        });
    }
};
