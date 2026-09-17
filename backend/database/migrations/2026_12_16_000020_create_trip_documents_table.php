<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-009 `trip_documents` — "LR/POD/EWB/attachments index", LOCKED. SNG-TRN-014.
 *
 * ── WHY THIS IS NOT `transport_documents` ────────────────────────────────
 * The registry draws the boundary itself, and the two rows say different things:
 *
 *   DB-019  transport_documents  "Vehicle/driver/customer documents"   CONTROLLED
 *   DB-009  trip_documents       "LR/POD/EWB/attachments index"        LOCKED
 *
 * One indexes the compliance paperwork a piece of MASTER DATA carries — a
 * vehicle's fitness certificate, a driver's licence. The other indexes the
 * paperwork a TRIP produces as it runs. A fitness certificate does not belong to
 * a journey and a POD does not belong to a vehicle. Building one table for both
 * would be the duplicate-canonical-entity failure FORBID-005 names, in the
 * direction of collapsing rather than duplicating — and it would put an
 * expiry-driven renewal model on a document that is evidence, not a licence.
 *
 * ── THE FIELD REGISTRY IS SILENT ─────────────────────────────────────────
 * DB_Fields has NO rows for DB-009 and Indexes_Constraints has none either.
 * Every column below is therefore named against the requirement that asks for
 * it, the same discipline `trip_advances` used where FLD-012 gave it one column.
 *
 *   trip_id            DB-009 is trip-scoped by its own name
 *   document_type      ENUM-006, the one registered vocabulary that fits —
 *                      lr|ewaybill|invoice|pod|... — reused rather than re-declared
 *   file_path/name/    CTR-012: `pod_file`, multipart FILE, required,
 *   mime/size          "allowed MIME/size", "signed upload"
 *   file_hash          MAM §104 "file hash where appropriate". For a POD it is
 *                      always appropriate: it is what makes "immutable after
 *                      verification" checkable rather than merely promised.
 *   status             the verification lifecycle — SEE BELOW
 *   verified_by/at     STT-008's actor and the evidence it happened
 *   rejection_reason   a refusal that cannot say why is not reviewable
 *
 * ── THE STATUS VOCABULARY IS CONSTRUCTED, AND THAT IS A DEFECT ───────────
 * Step 11 registers eight enums and four state machines (SM-ADV, SM-EXC,
 * SM-ORD, SM-TRP). **There is no document lifecycle in either.** ENUM-006 is
 * document_TYPE, not status. But STT-008 requires a "POD valid" guard, which is
 * a question only a status can answer, and 014's acceptance criterion turns on
 * it. So TripDocumentStatus is constructed and recorded as D-59.
 *
 * ── IMMUTABLE AFTER VERIFICATION — CTR-012, VERBATIM ─────────────────────
 * Enforced in the model and the service rather than by a DB trigger, because
 * SQLite and MySQL declare those differently and the rule needs a message
 * somebody can act on. The hash is what makes it auditable afterwards.
 *
 * ── NO UNIQUE ON (trip, type) ────────────────────────────────────────────
 * Deliberate. A trip legitimately carries several PODs — multi-drop deliveries
 * produce one per stop, and a rejected POD is followed by a replacement while
 * the rejected one stays as evidence. A unique key here would make the honest
 * case impossible. Duplicate protection is on the FILE instead: the same bytes
 * cannot be filed twice against the same trip, which is what a retried upload
 * actually looks like.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_documents')) {
            return;
        }

        Schema::create('trip_documents', function (Blueprint $table) {
            $table->id();

            // DB-009's tenant key is written `company_id`; this module has called
            // it `tenant_id` since SNG-TRN-001. Name differs, rule does not.
            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedBigInteger('trip_id');

            // ENUM-006, reused. Not re-declared — one vocabulary, one place.
            $table->string('document_type', 40);

            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // SHA-256, so 64 hex characters.
            $table->string('file_hash', 64);

            // Constructed — D-59. No registered document lifecycle exists.
            $table->string('status', 40)->default('received');

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The billing gate's question — "does this trip have a verified POD"
            // — runs on every billing attempt, so it gets the leading index.
            $table->index(['tenant_id', 'trip_id', 'document_type', 'status'], 'trip_documents_gate_idx');

            // A retried upload is the same bytes arriving twice. Refusing that
            // is the duplicate rule; refusing a second genuine POD is not.
            $table->unique(['tenant_id', 'trip_id', 'file_hash'], 'trip_documents_file_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_documents');
    }
};
