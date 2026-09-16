<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — comments, when the host has no notes system to borrow.
 *
 * NOT A SECOND NOTES SYSTEM
 *
 * A host WITH a notes system implements SireNotesProvider and this table stays
 * empty; SIRE comments then behave exactly like comments everywhere else in that
 * product. This table is the fallback that makes the provider optional, and
 * exactly one implementation is ever bound.
 *
 * That distinction matters: the failure mode to avoid is two comment systems
 * both holding real data. Binding is a single config key, so it cannot happen by
 * accident — only by changing the binding after comments exist, which
 * `sire:doctor` reports.
 *
 * POLYMORPHIC, BECAUSE SIRE COMMENTS ON MORE THAN ISSUES
 *
 * Reports, releases and recurrence groups all take comments. subject_type +
 * subject_id keeps that open without a column per subject.
 *
 * `is_internal` defaults to TRUE. An engineering issue discusses defects in a
 * customer's data, and nothing here should reach a customer-visible surface
 * unless somebody deliberately says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_notes')) {
            return;
        }

        Schema::create('sire_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id');

            $table->text('body');
            $table->boolean('is_internal')->default(true);

            $table->unsignedBigInteger('author_id')->nullable();
            // Snapshotted, not joined: a comment should still say who wrote it
            // after that person leaves and their user record is deactivated.
            $table->string('author_name', 191)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'sire_notes_subject_idx');
            $table->index(['tenant_id', 'created_at'], 'sire_notes_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_notes');
    }
};
