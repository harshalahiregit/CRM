<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNG-TRN-027 — Transport immutable audit trail.
 *
 * "As auditor, I can trace who changed what and when." (Step 12, ticket 027,
 * P0/S1). Step 9 P-008 "Audit by design": material changes must be attributable,
 * timestamped and reviewable. LOCK-006 forbids deleting or altering historical
 * audit evidence.
 *
 * This is infrastructure, not a business object — the Transport context owns no
 * business table until SNG-TRN-006. It is polymorphic so every future Transport
 * model plugs in without a new table each time, following the shape STOS-DB §23
 * sets out (organization, user, action, entity type, entity id, old values, new
 * values, IP, user agent, timestamp).
 *
 * APPEND-ONLY BY CONSTRUCTION. There is deliberately no `updated_at` and no
 * soft-delete column: a row that can be revised is not evidence. The model
 * blocks updating and deleting; the absence of updated_at means an accidental
 * ->update() would fail loudly rather than quietly rewriting history.
 *
 * occurred_at is separate from created_at because STOS-DB §19 distinguishes them
 * — an event that happened in the field at 06:10 and synced at 09:40 must keep
 * both times, or offline capture silently rewrites when things happened.
 *
 * Index names are explicit and short. Derived names would exceed MySQL's 64-char
 * identifier limit, which SQLite does not enforce — so it would pass the whole
 * test suite and abort the migration on the production MySQL box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // Polymorphic subject. Nullable so a context-level action with no
            // single subject (a permission denial, a bulk import) is recordable.
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Dotted action key, e.g. transport.order.created, transport.trip.status_changed.
            $table->string('action', 120);

            // Actor identity is snapshotted, not just referenced. A user row can
            // be renamed or soft-deleted later; the evidence must still say who
            // it was at the time.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 190)->nullable();
            $table->string('actor_role', 60)->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            // Free-form supporting detail: reason, source, correlation id.
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('occurred_at');
            // created_at only. No updated_at: see the class docblock.
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'transport_audit_tenant_subject_idx');  // 34
            $table->index(['tenant_id', 'action'], 'transport_audit_tenant_action_idx');                            // 33
            $table->index(['tenant_id', 'occurred_at'], 'transport_audit_tenant_occurred_idx');                     // 35
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_audit_logs');
    }
};
