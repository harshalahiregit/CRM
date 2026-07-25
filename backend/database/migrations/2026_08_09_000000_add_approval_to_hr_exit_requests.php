<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit / Separation Management — Phase 3 (Exit Approval Workflow).
 *
 * Additive columns on the existing hr_exit_requests table to carry the review
 * lifecycle (Submitted → Under Review → Approved / Rejected). No new table:
 * the polymorphic audit log already provides the approval history / timeline.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_exit_requests', function (Blueprint $table) {
            $table->timestamp('review_started_at')->nullable()->after('submitted_at');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_started_at');
            $table->text('review_remarks')->nullable()->after('reviewed_by');
            $table->timestamp('decided_at')->nullable()->after('review_remarks');
            $table->unsignedBigInteger('decided_by')->nullable()->after('decided_at');
            $table->text('decision_remarks')->nullable()->after('decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_exit_requests', function (Blueprint $table) {
            $table->dropColumn(['review_started_at', 'reviewed_by', 'review_remarks', 'decided_at', 'decided_by', 'decision_remarks']);
        });
    }
};
