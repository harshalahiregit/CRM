<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — the per-tenant issue type master.
 *
 * Created FIRST because sire_reports carries a logical link to it, and a register
 * whose types do not exist yet reads as a table of nulls.
 *
 * Seeded lazily by SireMasterService::ensureDefaults() on first access rather than
 * from a migration: looping every tenant here would run against a database shared
 * by two deployments, and hooking tenant creation would mean editing
 * AuthController — a shared-foundation change SIRE has no business making.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_report_categories')) {
            return;
        }

        Schema::create('sire_report_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');   // NOT NULL by design

            $table->string('name', 120);
            $table->string('code', 48);                // stable slug used by filters
            $table->string('description', 255)->nullable();

            $table->unsignedBigInteger('default_severity_id')->nullable();
            $table->unsignedBigInteger('default_assignee_id')->nullable();
            $table->boolean('requires_investigation')->default(false);
            // null defers to severity; true/false overrides it
            $table->boolean('requires_closure_approval')->nullable();
            $table->json('field_schema')->nullable();  // drives the `details` form

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'code'], 'sire_cat_tenant_code_uq');
            $table->index(['tenant_id', 'is_active'], 'sire_cat_tenant_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_report_categories');
    }
};
