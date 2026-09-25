<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subject_id cannot keep its foreign key to `tasks`.
 *
 * The column was task_id before the previous migration renamed it, and the
 * rename carried the constraint across — so the table now says "every row
 * points at a task" while the whole point of subject_type is that a row may
 * point at a project instead. Assigning anybody to a project failed on that
 * foreign key, and only did not fail when a task happened to share the
 * project's id.
 *
 * A polymorphic column cannot have a foreign key. Nothing replaces it: the
 * database can no longer check that a subject exists, so the service does —
 * every write resolves and tenant-checks the subject before writing.
 *
 * Little is lost. Both subjects are soft-deleted, so the cascade only ever
 * fired on a force-delete.
 *
 * ── Why a rebuild rather than dropForeign() ───────────────────────────────
 * SQLite cannot drop a foreign key by name ("This database driver does not
 * support dropping foreign keys by name"), and this project runs SQLite in
 * tests and MySQL in production. Rebuilding is the one path that behaves the
 * same on both, so both are exercised by the same code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_assignees_rebuilt', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('subject_type', 32)->default('task');
            // No constrained() here — that is the whole point of this migration.
            $table->unsignedBigInteger('subject_id');

            $table->string('party_type', 32);
            $table->unsignedBigInteger('party_id');
            $table->string('org_type', 32);
            $table->unsignedBigInteger('org_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'party_type', 'party_id'], 'party_subject_unique_v2');
            $table->index(['tenant_id', 'party_type', 'party_id'], 'party_lookup_v2');
            $table->index(['tenant_id', 'org_type', 'org_id'], 'party_org_v2');
        });

        $cols = 'id, tenant_id, subject_type, subject_id, party_type, party_id, org_type, org_id, name, email, assigned_by, created_at, updated_at';
        DB::statement("INSERT INTO party_assignees_rebuilt ($cols) SELECT $cols FROM party_assignees");

        Schema::drop('party_assignees');
        Schema::rename('party_assignees_rebuilt', 'party_assignees');
    }

    public function down(): void
    {
        // Putting the constraint back would reject every project row that exists
        // by then, so this deliberately does not. The column keeps its shape;
        // only the (already absent) constraint stays absent.
    }
};
