<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-020 `transport_policies` — "Configurable control policies". CONTROLLED.
 *
 * SNG-TRN-009 step 5. This is what makes the eligibility rules configurable
 * rather than hard-coded, which several documents require directly:
 *
 *   CMP §20  "The blocking rule must be configurable."
 *   CMP §24  "STOS-CMP determines what is required; STOS-DOC manages the
 *             evidence lifecycle."  — the required-document set is per-tenant.
 *   FLEET §11 "The system must not assume every vehicle requires exactly the
 *             same document set."
 *   FLEET §13 Expiry warning windows are configurable; "exact configuration
 *             belongs to the organization."
 *   Step 9 P-011 "Configurable, not bespoke."
 *
 * ── A KEY/VALUE STORE, AND WHY THAT IS ENOUGH HERE ────────────────────────
 * Shaped like the platform's existing per-module settings stores (see
 * PurchaseSettingService), because a policy here is a threshold or a flag, and
 * the DEFAULTS contract in TransportPolicyService declares every key a tenant
 * can set. Reading an unset key returns its default, so a tenant that has never
 * opened Settings behaves identically to one that has.
 *
 * ── DELIBERATELY ABSENT: VERSIONING AND EFFECTIVE DATES ───────────────────
 * Step 9's canonical object 17 describes Policy as "Versioned, effective-dated",
 * and CMP §7 asks for "effective dates; versioning" on compliance requirements.
 * Neither is built. A versioned, effective-dated policy engine is a platform
 * governance capability that no ticket in the 30-ticket register owns — there is
 * no compliance-engine ticket at all — and SNG-TRN-009 needs a threshold it can
 * read, not a temporal rules engine. Recorded rather than half-built: adding
 * effective_from/effective_to and a version column later is additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // Namespaced: '<subject>.<concern>[.<detail>]', e.g.
            // 'vehicle.required_documents', 'driver.check.licence.required'.
            $table->string('key', 120);

            // JSON so a policy can be a boolean, a number, or a list of document
            // types without three columns or a type discriminator. STOS-DB §219
            // permits JSON for "configuration" while forbidding it for core
            // business data — this is squarely the former.
            $table->json('value')->nullable();

            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One row per key per tenant. The uniqueness is what lets the service
            // treat "no row" as "use the default" without ambiguity.
            $table->unique(['tenant_id', 'key'], 'transport_policies_tenant_key_uniq'); // 34
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_policies');
    }
};
