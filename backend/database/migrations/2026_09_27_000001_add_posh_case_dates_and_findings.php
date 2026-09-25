<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dates a case carries, and the findings the committee reaches.
 *
 * DATES ARE COLUMNS, not a key/value table. There are four of them, each
 * single-valued per case, and a generic table would buy extensibility nobody
 * has asked for while turning "when did the inquiry start" into a join.
 *
 * NONE OF THEM IS A DEADLINE. They are recorded, never enforced. What the law
 * requires is unverified, and a period invented here would become permanent
 * the first time something checked it.
 *
 * Findings are separate from the round that produced them. The round is the
 * authoritative record of who decided what; the finding is what the committee
 * wrote about it, and it has its own life — drafted, frozen, and later
 * published as a distinct act. Collapsing the two would make publishing look
 * like deciding again.
 *
 * Additive only. No existing POSH row is read or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_posh_cases', function (Blueprint $table) {
            // When the complaint reached the company, as distinct from when
            // somebody typed it in — created_at already answers the latter.
            $table->timestamp('complaint_received_at')->nullable()->after('incident_place');
            $table->timestamp('acknowledged_at')->nullable()->after('complaint_received_at');
            $table->timestamp('inquiry_started_at')->nullable()->after('acknowledged_at');
            $table->timestamp('inquiry_completed_at')->nullable()->after('inquiry_started_at');
        });

        Schema::create('hr_posh_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id');

            // The round this finding reports on. Nullable because a case can
            // in principle be closed without an inquiry ever concluding.
            $table->unsignedBigInteger('round_id')->nullable();

            $table->text('summary')->nullable();
            $table->text('recommendation')->nullable();

            // draft | recorded. Publication is a timestamp, not a status —
            // a finding is recorded once and published once, and those are
            // two different facts about the same row.
            $table->string('status', 20)->default('draft');

            // Copied from the round when it concludes, so the finding carries
            // the verdict even if the round is later superseded.
            $table->string('outcome', 20)->nullable();

            $table->timestamp('recorded_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'case_id'], 'hr_posh_find_tenant_case_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_posh_findings');

        Schema::table('hr_posh_cases', function (Blueprint $table) {
            $table->dropColumn([
                'complaint_received_at', 'acknowledged_at',
                'inquiry_started_at', 'inquiry_completed_at',
            ]);
        });
    }
};
