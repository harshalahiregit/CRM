<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename with a real date before committing. Additive and idempotent: live has
 * repeatedly been found with migrations pending from earlier deploys, and one
 * migration was once recorded as run with no tables created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_report_contexts')) {
            return;
        }

        Schema::create('sire_report_contexts', function (Blueprint $table) {
            $table->id();

            // NOT NULL by design. The BelongsToTenant auto-stamp is guarded by
            // auth()->check(), so a nullable column silently absorbs NULL-tenant
            // rows written outside a request.
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('report_id'); // logical -> sire_reports

            $table->string('url', 1024)->nullable();   // redacted twice before it lands here
            $table->string('browser', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('viewport', 24)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('session_ref', 64)->nullable();

            $table->string('context_source', 24)->nullable();      // route | provider | user | dom | ...
            $table->string('context_confidence', 16)->nullable();  // high | medium | low
            $table->string('entity_source', 16)->nullable();

            $table->dateTime('captured_at')->nullable();

            // Metadata only: method, path, status, timestamp, correlation ref.
            // Never bodies, never headers.
            $table->json('failed_requests')->nullable();
            $table->json('page_context')->nullable();

            $table->timestamps();

            // Explicit short names — MySQL caps identifiers at 64 characters and
            // the SQLite test suite will not catch an overrun.
            $table->index('tenant_id');
            $table->index(['tenant_id', 'report_id'], 'sire_ctx_tenant_report_idx');
            $table->index('session_ref', 'sire_ctx_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_report_contexts');
    }
};
