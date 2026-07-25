<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compliance/HSSE checklist engine.
 *
 * Deliberately NOT a TPV table set: checklists attach polymorphically to any
 * entity (vendor today, project/worker/staff-exit next), because HR wants the
 * same engine for resignation/retirement checklists. Nothing in here references
 * a vendor.
 *
 * Statutory DOCUMENTS are out of scope by design — vendor_documents is already
 * the single reconciled document model for the onboarding + contractor doc sets.
 * This engine only ever deals in questionnaires.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Templates — the reusable form definition ─────────────────────
        Schema::create('compliance_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('code')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('Draft');   // Draft | Active | Archived

            // sections[] → questions[] — validated on write by ChecklistEvaluator,
            // never trusted at scoring time.
            $table->json('definition')->nullable();
            // Percent-of-maximum band cut-offs; null falls back to RiskBand defaults.
            $table->json('thresholds')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        // ── Checklist instances ─────────────────────────────────────────
        Schema::create('compliance_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('compliance_template_id')->index();

            // The subject. Nullable so a checklist can be standalone (a site
            // walk that belongs to no one record).
            $table->nullableMorphs('checklistable');

            $table->string('reference')->nullable();
            $table->string('title');
            $table->string('status')->default('Draft');

            // A 48-char bearer for unauthenticated fill-in. Hidden on the model;
            // possession of the link is the credential, so it is regenerated on
            // reopen and cleared once the checklist closes.
            $table->string('public_token', 64)->nullable()->unique();

            $table->unsignedBigInteger('issued_by')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            // A vendor's own supervisor may have no login — name/email let us
            // address a checklist to a person who exists only as a contact.
            $table->string('assignee_name')->nullable();
            $table->string('assignee_email')->nullable();
            $table->date('due_date')->nullable();

            $table->json('responses')->nullable();

            // Scoring output. Denormalised so the ledger can sort/filter without
            // re-running the evaluator over every row.
            $table->unsignedInteger('score')->nullable();
            $table->unsignedInteger('max_score')->nullable();
            $table->decimal('risk_percent', 5, 2)->nullable();
            $table->string('risk_band')->nullable();        // Low | Moderate | High
            $table->json('critical_failures')->nullable();  // question keys that hard-gate to High

            // Submission capture — who/where/what, per the brief.
            $table->timestamp('submitted_at')->nullable();
            $table->string('selfie_path')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('submitted_ip')->nullable();
            $table->string('submitted_user_agent')->nullable();

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'risk_band']);
        });

        // ── The 3-tier signature chain ──────────────────────────────────
        // A row per action rather than columns on the checklist: every tier
        // carries its own remark and signature, and a reopened checklist keeps
        // the earlier chain instead of overwriting it.
        Schema::create('compliance_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('compliance_checklist_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('tier');      // issuer | manager | head
            $table->string('action');    // approve | reject
            $table->text('remarks')->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();

            // True when an admin knowingly signed off their own issue. Kept as a
            // column rather than buried in audit metadata because "which sign-offs
            // bypassed segregation of duties" is exactly the question a compliance
            // lead asks, and it should be one WHERE clause away.
            $table->boolean('segregation_overridden')->default(false);

            $table->timestamps();
            $table->index(['tenant_id', 'compliance_checklist_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_signatures');
        Schema::dropIfExists('compliance_checklists');
        Schema::dropIfExists('compliance_templates');
    }
};
