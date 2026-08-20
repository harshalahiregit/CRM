<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A / slice 2 — the Action Engine (Meeting.docx §8).
 *
 * A MOM item stops being "just text" and becomes a trackable action with a
 * lifecycle (Open → In Progress → Pending Verification → Closed → Reopened),
 * an owner org, a priority, an evidence file, and a verification stamp — so it
 * can be chased to closure after the meeting, not only edited on the form.
 *
 * All additive and nullable/defaulted: existing rows read as Open with no
 * evidence, exactly the state they were already in. `agenda_item_id` optionally
 * links an action back to the agenda item it came from (structured MOM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            // Human reference (ACT-YYYY-NNNN), assigned on create.
            $table->string('action_ref', 32)->nullable()->after('kickoff_meeting_id');

            // Lifecycle. Defaulted so every existing row is a valid Open action.
            $table->string('status', 24)->default('Open')->after('sort_order');
            $table->string('priority', 20)->nullable()->after('status');
            $table->string('responsible_org', 160)->nullable()->after('responsible_names');

            // Closure evidence + verification (rule 12: closure requires evidence).
            // verification_note is owned by the Action Engine, NOT the meeting form,
            // so a form re-save can't overwrite what the verifier recorded.
            $table->string('evidence_path')->nullable()->after('priority');
            $table->text('verification_note')->nullable()->after('evidence_path');
            $table->timestamp('closed_at')->nullable()->after('verification_note');
            $table->timestamp('verified_at')->nullable()->after('closed_at');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');

            // Optional link to the agenda item this action came out of.
            $table->unsignedBigInteger('agenda_item_id')->nullable()->after('kickoff_meeting_id')->index();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['agenda_item_id']);
            $table->dropColumn([
                'action_ref', 'status', 'priority', 'responsible_org',
                'evidence_path', 'closed_at', 'verified_at', 'verified_by', 'agenda_item_id',
            ]);
        });
    }
};
