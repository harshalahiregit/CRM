<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk Phase 1 — admin-configurable support settings (owner: Shivam).
 *
 * SQLite-safe, additive, reversible. Introduces tenant-managed lookup lists for
 * priorities, statuses and departments (Freshdesk "Support Settings" parity) plus
 * a per-tenant helpdesk_settings row.
 *
 * SCHEMA CONTRADICTION FIXED (flagged to management): the master spec assumed
 * tickets.status was already a string like priority. It was NOT — migration
 * 000002 only converted priority; status was still an enum('open','in-progress',
 * 'closed'). Custom statuses (Phase 1) and the 'merged' status (Phase 3) can't
 * exist inside that CHECK constraint, so we convert status → string here, exactly
 * the way priority was converted (values now enforced by FormRequests against the
 * tenant's configured list).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Departments ─────────────────────────────────────────────
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        // ── Priorities ──────────────────────────────────────────────
        Schema::create('ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');                 // also the value stored in tickets.priority
            $table->string('color', 9)->default('#94a3b8');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
        });

        // ── Statuses ────────────────────────────────────────────────
        Schema::create('ticket_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');                 // also the value stored in tickets.status
            $table->string('color', 9)->default('#94a3b8');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_closed_status')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
        });

        // ── Per-tenant helpdesk settings (single row) ───────────────
        Schema::create('helpdesk_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('public_form_enabled')->default(true);
            // with_logo | without_logo (string, not enum — SQLite-safe)
            $table->string('public_form_logo_variant', 20)->default('with_logo');
            $table->foreignId('default_department_id')->nullable()
                  ->constrained('ticket_departments')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });

        // ── Tickets: status enum → string + department_id FK ────────
        Schema::table('tickets', function (Blueprint $table) {
            // The flagged fix: enum → varchar so custom / 'merged' statuses are allowed.
            $table->string('status', 30)->default('open')->change();
            $table->foreignId('department_id')->nullable()->after('customer_id')
                  ->constrained('ticket_departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            // status is left as a string on rollback — reverting to enum would be a
            // lossy CHECK-constraint rebuild on SQLite and would reject existing rows.
        });

        Schema::dropIfExists('helpdesk_settings');
        Schema::dropIfExists('ticket_statuses');
        Schema::dropIfExists('ticket_priorities');
        Schema::dropIfExists('ticket_departments');
    }
};
