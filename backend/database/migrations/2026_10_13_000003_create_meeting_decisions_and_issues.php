<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A / slice 3 — the Decision Register (Meeting.docx §9) and Issues Raised
 * (§10), as first-class children of a meeting alongside agenda items and actions.
 *
 * A decision that used to be buried in prose minutes is now a searchable record;
 * an issue is trackable and can be escalated (converted) into an Incident etc.
 * Both hang off kickoff_meeting_id and inherit the meeting's vendor/subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            $table->string('decision_ref', 32)->nullable();   // DEC-YYYY-NNNN
            $table->text('decision');
            // Who took it — an attendee where known, else free-typed names.
            $table->unsignedBigInteger('decided_by_attendee_id')->nullable()->index();
            $table->string('decided_by_names', 300)->nullable();
            $table->text('impact')->nullable();
            $table->date('effective_date')->nullable();
            // Active | Superseded | Rescinded.
            $table->string('status', 20)->default('Active');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'kickoff_meeting_id']);
        });

        Schema::create('meeting_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            $table->string('issue_ref', 32)->nullable();   // ISS-YYYY-NNNN
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            // Low | Medium | High | Critical.
            $table->string('severity', 20)->nullable();

            $table->unsignedBigInteger('owner_attendee_id')->nullable()->index();
            $table->string('owner_names', 300)->nullable();
            $table->date('due_date')->nullable();

            // Open | In_Progress | Resolved | Closed | Reopened | Cancelled.
            $table->string('status', 24)->default('Open');

            // Escalation marker — what the issue was converted into and the record
            // it produced (e.g. converted_to='Incident', converted_ref='INC-2026-003').
            $table->string('converted_to', 24)->nullable();
            $table->string('converted_ref', 64)->nullable();
            $table->unsignedBigInteger('converted_id')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'kickoff_meeting_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_issues');
        Schema::dropIfExists('meeting_decisions');
    }
};
