<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision rounds — a fixed set of people answering one question at once.
 *
 * The codebase already has an approval engine, and it answers a different
 * question: "whose turn is it next". Its current_step is a single integer, the
 * first decider at a step closes it, and a rejection anywhere ends the whole
 * request. None of those can express a committee deliberating, or five
 * departments clearing an exit independently.
 *
 * These two tables do only that. There is no ladder, no ordering, no approver
 * vocabulary, no conditions, no notification policy and no configuration of who
 * is asked — the caller resolves the roster and the round freezes it.
 *
 * Nothing is migrated onto this yet. Exit Clearance keeps its own items and its
 * own recompute() until a separate, reviewed change moves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_decision_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // Polymorphic, as hr_request_messages already is: the round hangs
            // off whatever the domain is deciding about.
            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id');

            // A label for the caller's own benefit. NOT a registry key: the
            // round behaves identically whatever this says, and giving it
            // behaviour would be the first step towards a second engine.
            $table->string('purpose', 60);

            $table->string('mode', 20);                       // all_of | quorum
            $table->unsignedSmallInteger('quorum_required')->nullable();

            $table->string('state', 30)->default('open');
            $table->string('outcome', 20)->nullable();        // only when decided

            // Why the round ended as it did, in words — a tie, an unreachable
            // quorum, the reason for a supersede. Stored, not derived.
            $table->text('closing_note')->nullable();

            // A roster or quorum change never edits a frozen round; it closes
            // this one and opens another. This is the thread between them.
            $table->unsignedBigInteger('superseded_by_round_id')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Named explicitly. The generated name for the subject index would
            // be hr_decision_rounds_tenant_id_subject_type_subject_id_index at
            // 59 characters — inside MySQL's 64 limit, but this codebase has
            // already shipped one 65-character index that only SQLite tolerated.
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'hr_dec_rounds_tenant_subject_idx');
            $table->index(['tenant_id', 'state'], 'hr_dec_rounds_tenant_state_idx');
        });

        Schema::create('hr_decision_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('round_id');

            // What this participant stands for — a department, a committee
            // seat. The caller's word, carried so the round can be read back
            // without asking the domain what slot 3 was.
            $table->string('slot_key', 60);
            $table->string('slot_label', 150)->nullable();

            // Optional participants may decide and are recorded, and never
            // affect the outcome. Exit Clearance's is_mandatory, generalised.
            $table->boolean('is_required')->default(true);

            // HOW membership was resolved, kept for the record. The round never
            // re-resolves it: resolved_user_ids below is authoritative.
            $table->string('resolver_type', 40)->nullable();
            $table->string('resolver_ref', 120)->nullable();

            // THE FREEZE. Whoever could act when the round opened, and nobody
            // else — a later change to a committee or a department mapping
            // cannot reach a round somebody is halfway through.
            $table->json('resolved_user_ids')->nullable();

            $table->string('decision', 20)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 150)->nullable();
            $table->timestamp('decided_at')->nullable();

            // A recusal without a reason is not a record of anything.
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'round_id'], 'hr_dec_parts_tenant_round_idx');
            $table->unique(['round_id', 'slot_key'], 'hr_dec_parts_round_slot_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_decision_participants');
        Schema::dropIfExists('hr_decision_rounds');
    }
};
