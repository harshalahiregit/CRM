<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-019 `transport_documents` — "Vehicle/driver/customer documents".
 *
 * Built during SNG-TRN-003 because vehicle compliance needs somewhere to live,
 * and reused unchanged by SNG-TRN-004 for driver documents. One polymorphic
 * table, not one per entity: the registry declares a single entity and
 * non-negotiable rule 3 forbids duplicating it.
 *
 * ── THE UNIQUE KEY IS THE POINT OF THIS TABLE ─────────────────────────────
 * IDX-010, verbatim: UNIQUE(company_id, entity_type, entity_id, document_type, version)
 * with reason "document integrity". Reproduced exactly (tenant_id for company_id
 * per the standing ruling). It is what makes STOS-DOC §26 enforceable:
 *   "When a document is replaced: do not silently overwrite the previous
 *    version. Maintain Version 1, Version 2, Version 3 with history."
 * A new version is a new row. Correcting an insurance certificate never destroys
 * the evidence of what was on file when a trip was allocated last month — which
 * is the entire reason an allocation audit is worth keeping.
 *
 * ── COLUMNS ARE STOS-DOC §24, NOT INVENTION ───────────────────────────────
 * §24 "Every uploaded document should store": Document ID, Type, File, Related
 * object, Uploaded by, Uploaded date/time, Source, Version, Status, Verification
 * status, Expiry where applicable. Step 11 specifies exactly one field for
 * DB-019 (FLD-020 document_type), so the rest is reference tier — defect D-3.
 *
 * DELIBERATELY ABSENT, and why:
 *   verification_status, rejection_reason  §24/§27/§28 describe a verification and
 *     correction workflow. Ticket 003 is CRUD whose acceptance is "document dates";
 *     nothing in the P0 allocation set reads a verification state. Columns nothing
 *     writes are worse than columns added when their ticket arrives.
 *   renewal tasks  FLEET §12 and BRWM both require an expiring document to
 *     create a renewal task. Not built: no P0 ticket owns a transport task
 *     engine, and the platform task module is out of bounds for this ticket. The
 *     valid_until column and its index are the inputs a future sweep needs.
 *   is_critical  FLEET §14 blocks allocation on an expired CRITICAL document, but
 *     CMP §20 is explicit that "the blocking rule must be configurable". Criticality
 *     is therefore a policy keyed by document_type (transport_policies, DB-020,
 *     SNG-TRN-009 step 5) — not a per-row boolean somebody has to remember to tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // §24 "Related object". Plain strings, not Laravel's FQCN convention:
            // this pair is half of a registry-specified cross-system unique key,
            // and "vehicle" stays readable and stable if a class is ever moved.
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');

            // FLD-020 — the one field Step 11 actually specifies. ENUM-006.
            $table->string('document_type', 40);

            // §26 — versions are rows, never overwrites. Starts at 1.
            $table->unsignedInteger('version')->default(1);

            // The document's own reference: policy number, RC number, permit number.
            $table->string('document_number', 80)->nullable();

            // §24 "Expiry where applicable" and the ticket's own acceptance
            // criterion, "document dates". valid_until is what every compliance
            // check in SNG-TRN-009 reads, so it carries its own index.
            $table->date('issued_on')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            // §24 "File". Nullable: a certificate's DATES are what block a trip,
            // and requiring the scan before the expiry date can be recorded would
            // make the compliance gate unusable during onboarding.
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_hash', 64)->nullable();   // §104, "file hash where appropriate"

            // §25 — Driver, Operations, Customer, Vendor, System, API, Scanner,
            // Email, Integration. Free-form: the list is reference tier and has no
            // canonical enum, so it is not constrained into one here.
            $table->string('source', 30)->nullable();

            // §24 "Status". 'active' is the live version; superseded rows stay for
            // history. Not an enum — no canonical values exist for it.
            $table->string('status', 20)->default('active');

            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // IDX-010, reproduced exactly.
            $table->unique(
                ['tenant_id', 'entity_type', 'entity_id', 'document_type', 'version'],
                'transport_documents_identity_uniq'                                     // 33
            );
            // "Every document for this vehicle" — the compliance panel's query.
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'transport_documents_entity_idx');   // 30
            // "What lapses soon / has lapsed" — the eligibility check and, later,
            // FLEET §13's configurable expiry warning windows.
            $table->index(['tenant_id', 'valid_until'], 'transport_documents_expiry_idx');                // 30
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_documents');
    }
};
