<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offer lifecycle completion (additive).
 *
 * `hr_offers.status` is already a free-form string (relaxed from the original enum
 * in 2026_07_13_065412), so the new states — Draft / Pending Approval / Approved /
 * Withdrawn — need NO enum change. This only adds the columns those states require
 * plus an immutable revision-history table. Existing rows and flows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            // Approval sub-flow (Pending Approval → Approved).
            $table->timestamp('submitted_for_approval_at')->nullable()->after('sent_at');
            $table->unsignedBigInteger('approved_by')->nullable()->after('submitted_for_approval_at');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            // Withdraw (reason mandatory) before acceptance.
            $table->timestamp('withdrawn_at')->nullable()->after('approved_at');
            $table->text('withdraw_reason')->nullable()->after('withdrawn_at');
            // Current revision number of this offer row (bumped by revise()).
            $table->unsignedInteger('version')->default(1)->after('withdraw_reason');
        });

        // Immutable snapshot of every prior offer version. Never updated after insert,
        // never deleted — the full history (values + the preserved PDF path) lives here.
        Schema::create('hr_offer_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offer_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedInteger('version');
            $table->json('snapshot');                 // full field snapshot of the superseded offer
            $table->string('letter_path')->nullable(); // preserved PDF of the superseded version
            $table->unsignedBigInteger('revised_by')->nullable();
            $table->string('revised_by_name')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestamps();                      // created_at == revised_at

            $table->foreign('offer_id')->references('id')->on('hr_offers')->cascadeOnDelete();
            $table->index(['offer_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offer_revisions');
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_for_approval_at', 'approved_by', 'approved_at',
                'withdrawn_at', 'withdraw_reason', 'version',
            ]);
        });
    }
};
