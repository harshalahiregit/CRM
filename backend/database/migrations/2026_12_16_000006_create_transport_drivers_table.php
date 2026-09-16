<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNG-TRN-004 — Driver master.
 *
 * Ticket: "As an operator, I can maintain driver and license information."
 * Acceptance: "Required fields, validity dates, audit."  Tests: "CRUD + expiry tests."
 *
 * Scope is RTM §18, the same structure that scoped allocation:
 *   DRV-001 Maintain driver profile        P0  <- this table
 *   DRV-002 Maintain driver documents      P0  <- transport_documents, entity_type='driver'
 *   DRV-003 Track driver availability      P0  <- availability column
 *   MDM-006 Maintain driver master         P0  <- "status and documents available"
 * Deferred, each with a reason recorded in the step-2 report: DRV-004 long-term
 * leave (P1), DRV-005 replacement (P1), DRV-007 incidents (P1), DRV-008 feedback
 * (P0 but CTD/QC, attaches to POD in SNG-TRN-014).
 *
 * ── TABLE NAME ────────────────────────────────────────────────────────────
 * Step 11 DB-005 names this `drivers`; this creates `transport_drivers`, under
 * the same standing ruling that produced transport_vehicles. See that migration.
 *
 * ── REGISTRY DEFECTS THIS TABLE SITS ON ───────────────────────────────────
 * D-2  Ticket 004 cites DB-004, which is `vehicles`. The driver entity is DB-005.
 * D-3  Step 11's DB_Fields specifies ZERO columns for DB-005. Every column below
 *      is reference tier: STOS-DB §42-44/§152, CMP §22, BRM BR-044/045/048,
 *      CTD §21, INT §76, Step 2 BO-009.
 * D-6  Step 5's data model contradicts Step 11 — it defines a separate
 *      `driver_documents` table with UUID keys. Steps 1-8 cannot override Step 11,
 *      so documents live in DB-019 transport_documents and keys stay bigint.
 * D-7  STOS-DB §152 mandates an index on "driver code/employee reference" and §16
 *      requires a public reference number, but no field registry defines either.
 *      driver_code below is that field, created to satisfy an index rule whose
 *      column the registry never specified.
 *
 * ── WHAT IS DELIBERATELY ABSENT ───────────────────────────────────────────
 * driver_leave_records      STOS-DB §45, BRWM §10. P1 (DRV-004), no Step 11 entity.
 *                           ON_LEAVE is therefore set directly, not derived.
 * performance score         CTD §21, BRM BR-049, LSM §41. P1.
 * incidents                 CTD §21, BRM BR-050/051, QC §102-106. P1 (DRV-007).
 * feedback                  CTD §21, DRV-008. Belongs to POD (SNG-TRN-014).
 * document_verifications    STOS-DB §80. Verification workflow; no P0 ticket.
 * document_handover_records STOS-DB §81. Trip execution.
 * attendance-derived availability  STOS-DB §120 puts attendance in HR. Reading it
 *                           would mean touching the HR module, which is forbidden
 *                           in this ticket, so availability is set within Transport.
 * PII masking               SEC §41, CTD §21 "contact where authorized". No P0
 *                           ticket owns field-level RBAC masking. Flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // STOS-DB §16 "public/reference number" + §152 "driver code/employee
            // reference should be indexed". Nullable because a driver onboarded in
            // a hurry has a name and a licence before anyone allocates a code;
            // unique per tenant when present, so it can be relied on once set.
            $table->string('driver_code', 40)->nullable();

            // CTD §21. The one genuinely required field: a driver record with no
            // name identifies nobody.
            $table->string('name', 150);

            // CTD §21 "contact where authorized", CTD §49 "driver phone".
            $table->string('mobile', 20)->nullable();
            $table->string('alternate_mobile', 20)->nullable();

            // STOS-DB §42: "Prefer linking driver to Sangoe HR employee record
            // rather than creating unrelated duplicate identities." §117: "STOS
            // should reference employee_id / driver_id."
            // No foreign key: HR is another module and this ticket may not touch it.
            // Unique per tenant, which is how INT §76's "avoid duplicate driver
            // profiles" becomes enforceable rather than aspirational — one employee
            // cannot end up with two driver records. NULLs are exempt from a MySQL
            // unique index, so contractor drivers are unaffected.
            $table->unsignedBigInteger('hr_employee_id')->nullable();

            // Step 2 BO-009 scopes a driver to "Company/supplier". A supplier-
            // provided driver has no employee record. DB-018 transport_suppliers is
            // not built, so this follows the convention transport_trips already uses
            // for vehicle_id/driver_id: agreed column name, nullable, no constraint
            // yet, so wiring it later is one line rather than a rename.
            $table->unsignedBigInteger('supplier_id')->nullable();

            // ── Licence. Authoritative here, per the owner's ruling. ──────────
            // transport_documents holds the SCAN and every other driver document;
            // these columns are the single source the eligibility check reads, so
            // the expiry date exists in exactly one place (STOS-DB §166).
            // Nullable, unlike a vehicle's registration: a vehicle without a
            // registration number is not a vehicle, but a driver being onboarded is
            // a real person whose licence is still being collected. Allocation is
            // where a missing licence bites (BR-P0-004), not data entry.
            $table->string('licence_number', 40)->nullable();
            $table->string('licence_normalized', 40)->nullable();
            // CMP §22 "licence class", BRW-030, BR-048 "category".
            $table->string('licence_class', 30)->nullable();
            // Ticket acceptance says "validity dates", plural.
            $table->date('licence_valid_from')->nullable();
            $table->date('licence_valid_until')->nullable();

            // Two axes, deliberately two columns. See DriverStatus for the reasoning.
            $table->string('status', 20)->default('active');          // BO-009 lifecycle
            $table->string('availability', 20)->default('available'); // STOS-DB §44 + ASSIGNED

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Explicit short names; MySQL's 64-char cap is asserted by a test with
            // no grandfathered exceptions.
            $table->unique(['tenant_id', 'licence_normalized'], 'transport_drivers_tenant_licence_uniq'); // 37
            $table->unique(['tenant_id', 'driver_code'], 'transport_drivers_tenant_code_uniq');           // 34
            $table->unique(['tenant_id', 'hr_employee_id'], 'transport_drivers_tenant_emp_uniq');         // 33
            $table->index(['tenant_id', 'availability'], 'transport_drivers_tenant_avail_idx');           // 34
            $table->index(['tenant_id', 'status'], 'transport_drivers_tenant_status_idx');                // 35
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_drivers');
    }
};
