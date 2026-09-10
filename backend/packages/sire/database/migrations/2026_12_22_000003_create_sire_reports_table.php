<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — the register. The central table every other SIRE table points at.
 *
 * SCOPE OF THIS FILE: the base columns only. Later migrations add the engineering
 * workflow (008), SLA tracking (010), quality and governance (018) and release
 * governance (019). Keeping them separate means each phase's schema arrives with
 * the code that uses it, and a partial deploy leaves a coherent table rather than
 * a half-populated one.
 *
 * DELIBERATELY ABSENT: the Phase 0 incident-register fields — `track`,
 * `investigator_id`, `root_cause`, `is_reportable`, `estimated_cost` and the rest.
 * SIRE became an internal engineering issue system, root cause moved to its own
 * table, and shipping columns nothing reads is how a schema stops describing the
 * product.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_reports')) {
            return;
        }

        Schema::create('sire_reports', function (Blueprint $table) {
            $table->id();

            /*
             * NOT NULL by design. The BelongsToTenant auto-stamp is guarded by
             * auth()->check(), which is false in a scheduled command or any
             * token-resolved route — a nullable column silently absorbs that
             * mistake and produces rows belonging to nobody.
             */
            $table->unsignedBigInteger('tenant_id');

            $table->string('report_number', 32);
            $table->string('status', 32)->default('new');

            $table->unsignedBigInteger('category_id')->nullable();  // logical -> sire_report_categories
            $table->unsignedBigInteger('severity_id')->nullable();  // logical -> sire_severities

            $table->string('title', 255);
            $table->text('description');
            $table->json('details')->nullable();       // category-defined capture

            $table->dateTime('occurred_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // internal | client_portal | vendor_portal | converted | import
            $table->string('origin', 32)->default('internal');

            // Logical polymorphic link to whatever the issue is ABOUT.
            $table->string('related_type', 64)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->string('reporter_name', 160)->nullable();
            $table->string('reporter_email', 160)->nullable();
            $table->boolean('is_anonymous')->default(false);

            $table->unsignedBigInteger('assignee_id')->nullable();

            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('triaged_at')->nullable();
            $table->unsignedBigInteger('triaged_by')->nullable();

            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->dateTime('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->unsignedSmallInteger('reopen_count')->default(0);

            $table->unsignedBigInteger('duplicate_of_id')->nullable();  // self-reference, logical

            // Superseded by the per-clock columns in migration 010, which drops it.
            $table->string('sla_notified_state', 16)->nullable();

            // ---- Report Issue capture (see 12-… phase 0) --------------------
            // Promoted to real columns so the register can filter and group by
            // them; the diagnostic detail lives in sire_report_contexts.
            $table->string('module', 64)->nullable();
            $table->string('section', 64)->nullable();
            $table->string('screen', 96)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('context_confidence', 16)->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * Named short and explicitly. MySQL caps identifiers at 64 characters
             * and Laravel's generated names exceed it; the suite runs on SQLite,
             * which does not care, so an overrun fails in production and nowhere
             * else. That has already broken a live deploy in this codebase.
             */
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'report_number'], 'sire_rep_tenant_number_uq');
            $table->index(['tenant_id', 'status'], 'sire_rep_tenant_status_idx');
            $table->index(['tenant_id', 'created_at'], 'sire_rep_tenant_created_idx');
            $table->index(['tenant_id', 'assignee_id'], 'sire_rep_tenant_assignee_idx');
            $table->index(['tenant_id', 'severity_id'], 'sire_rep_tenant_severity_idx');
            $table->index(['tenant_id', 'occurred_at'], 'sire_rep_tenant_occurred_idx');
            $table->index(['tenant_id', 'module'], 'sire_rep_tenant_module_idx');
            $table->index(['related_type', 'related_id'], 'sire_rep_related_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_reports');
    }
};
