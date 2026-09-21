<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-DOC — the verification axis (T-53, T-57).
 *
 * Ruled 2026-09-19: an unverified upload must NEVER equal a verified document.
 * A truck is not cleared for dispatch because a file was attached to it — the
 * certificate has to be looked at. Raw OCR extraction does not count either.
 *
 * ── WHY A NEW COLUMN AND NOT `status` ─────────────────────────────────────
 * `transport_documents.status` already means something else: `active` or
 * `superseded`, which is the VERSIONING axis — whether this row is the current
 * version or one that a renewal replaced. Verification is a different question
 * about the same row, and overloading one column with two meanings is how a
 * superseded-but-verified document becomes indistinguishable from a current
 * one nobody has checked.
 *
 * ── EXISTING ROWS BECOME `UPLOADED`, NOT `VERIFIED` ───────────────────────
 * Everything already filed was filed before verification existed, so nobody
 * verified it. Marking it VERIFIED would be a lie written into the audit trail,
 * and the whole point of this column is that the trail can be trusted.
 *
 * Nothing breaks today as a result: the dispatch gate still reads Fleet's own
 * date columns, not documents. It is when the projection goes live (T-53) that
 * the backlog has to be verified — and that is exactly the moment somebody
 * SHOULD look at every certificate the fleet is relying on.
 *
 * ── OWNERSHIP ─────────────────────────────────────────────────────────────
 * The evidence lifecycle belongs to STOS-DOC (Person 3). These columns are the
 * seam Fleet needs in order to stop trusting unverified paperwork, added with
 * the owner's ruling behind them. The verification SCREEN and its workflow are
 * Person 3's to build; Fleet only reads the verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_documents', function (Blueprint $table) {
            // UPLOADED · UNDER_VERIFICATION · VERIFIED · REJECTED
            $table->string('verification_status', 24)->default('UPLOADED')->after('status');
            $table->dateTime('verified_at')->nullable()->after('verification_status');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');
            $table->string('rejection_reason', 255)->nullable()->after('verified_by');

            // The projection asks one question over and over: "what is the
            // newest VERIFIED document of this type for this entity?"
            $table->index(['tenant_id', 'verification_status'], 'transport_documents_verification_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transport_documents', function (Blueprint $table) {
            $table->dropIndex('transport_documents_verification_idx');
            $table->dropColumn(['verification_status', 'verified_at', 'verified_by', 'rejection_reason']);
        });
    }
};
