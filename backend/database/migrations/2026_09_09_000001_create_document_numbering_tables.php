<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document Numbering Engine — storage.
 *
 * Two tables, deliberately split:
 *
 *  - `document_number_configs`   IMMUTABLE-ish CONFIGURATION per tenant+document type.
 *    It holds NO mutable sequence cursor: the "current number" is derived from the
 *    sequence table at read time, so configuration edits can never corrupt an
 *    in-flight counter (and two admins saving the form cannot clobber a sequence).
 *
 *  - `document_number_sequences` RUNTIME STATE ONLY. One row per
 *    (tenant, document_type, period_key). A reset or a new period never mutates or
 *    deletes history — it simply causes a NEW row to be created, so previously
 *    issued ranges stay auditable.
 *
 * Row-level multi-tenancy, no DB foreign keys — matching the rest of the app. The
 * unique index on the sequence table is the real last line of defence against
 * duplicate numbers (lockForUpdate is a no-op on SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_number_configs')) {
            Schema::create('document_number_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('document_type', 60);

                // Format + presentation
                $table->string('format', 191)->default('{PREFIX}{NEXT}');
                $table->string('prefix', 30)->default('');
                $table->string('suffix', 30)->default('');
                $table->unsignedSmallInteger('minimum_digits')->default(4);
                $table->string('padding', 1)->default('0');

                // Sequence policy (NOT the cursor itself — see class docblock)
                $table->unsignedInteger('starting_number')->default(1);
                $table->string('reset_rule', 30)->default('never');
                // Bumped on every manual reset; forms part of the period key so a
                // reset opens a NEW sequence row instead of destroying the old one.
                $table->unsignedInteger('epoch')->default(0);

                // Behaviour flags
                $table->boolean('enabled')->default(false);
                $table->boolean('locked')->default(false);
                $table->boolean('manual_override')->default(false);
                $table->boolean('decrement_on_delete')->default(false);

                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'document_type'], 'dnc_tenant_type_unique');
            });
        }

        if (! Schema::hasTable('document_number_sequences')) {
            Schema::create('document_number_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('document_type', 60);
                // e.g. "never:0", "Y2026:0", "FY2026-27:1", "M2026-09:0", "D2026-09-09:0"
                $table->string('period_key', 60);
                $table->unsignedBigInteger('current_sequence')->default(0);
                $table->timestamps();

                // The portable guarantee that two allocations can never collide.
                $table->unique(['tenant_id', 'document_type', 'period_key'], 'dns_tenant_type_period_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
        Schema::dropIfExists('document_number_configs');
    }
};
