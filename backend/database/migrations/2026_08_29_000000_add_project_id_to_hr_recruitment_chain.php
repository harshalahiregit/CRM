<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project integration for the HR recruitment chain (additive).
 *
 * Adds a nullable, indexed `project_id` FK that points at the EXISTING `projects`
 * table (App\Models\Project\Project) to Manpower Request → Job Posting → Candidate →
 * Employee. No new project table, no duplicate mapping — the id is carried forward
 * down the chain and the project name is resolved through a relationship.
 *
 * No DB-level FK constraint (matching the Projects module's own `customer_id`
 * convention and keeping the ALTER SQLite-safe); integrity is enforced by the
 * tenant-scoped `exists` rule in the FormRequests. Existing rows keep working —
 * `project_id` is nullable and never back-filled destructively.
 */
return new class extends Migration
{
    private array $tables = [
        'hr_manpower_requests',
        'hr_job_postings',
        'hr_candidates',
        'hr_employees',
    ];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'project_id')) {
                continue;   // idempotent
            }
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('tenant_id');
                $table->index('project_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            if (! Schema::hasColumn($t, 'project_id')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['project_id']);
                $table->dropColumn('project_id');
            });
        }
    }
};
