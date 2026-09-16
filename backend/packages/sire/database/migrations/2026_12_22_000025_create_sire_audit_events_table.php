<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — the audit trail, when the host has no audit system to borrow.
 *
 * WHY SIRE NOW OWNS ONE
 *
 * Previously SIRE wrote system events into the host's `audit_logs` table and, if
 * that table was absent, logged to a file. A log file is not an audit trail: you
 * cannot render a timeline from it, and the timeline is a core SIRE feature. A
 * host without an audit system therefore got a SIRE whose issue pages showed no
 * history at all.
 *
 * So this is the fallback. A host WITH an audit system implements
 * SireAuditProvider and this table stays empty. Exactly one is ever bound —
 * there is never a second trail.
 *
 * NO UPDATED_AT, AND THAT IS THE POINT
 *
 * This table has `created_at` and nothing else. There is no update path, no
 * delete path, no soft delete, and no SIRE endpoint that edits a row here — a
 * test asserts no such route exists. Release approvals and emergency overrides
 * are defended by this trail, and a history that can be rewritten afterwards
 * proves nothing about what happened.
 *
 * The actor is SNAPSHOTTED into actor_name/actor_role rather than joined. A trail
 * that goes blank when someone leaves the company is not a trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_audit_events')) {
            return;
        }

        Schema::create('sire_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id');

            $table->string('action', 191);
            $table->text('comment')->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('actor_role', 64)->nullable();

            // Before/after are separate from metadata so a diff view never has to
            // guess which keys described a change and which described the action.
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();

            // created_at only. See the note above — there is no updated_at
            // because there is no update.
            $table->dateTime('created_at')->nullable();

            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'sire_audit_subject_idx');
            $table->index(['tenant_id', 'created_at'], 'sire_audit_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_audit_events');
    }
};
