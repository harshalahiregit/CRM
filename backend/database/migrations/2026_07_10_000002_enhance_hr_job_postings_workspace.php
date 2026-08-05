<?php

use App\Support\Hr\JobPostingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job Posting → Recruitment Workspace enhancement (Phase 1).
 *
 * 1. Widens `status` from enum(Active,Draft,Closed) to a string supporting the
 *    full lifecycle (Draft, Ready_for_HR, Published, Hiring, Partially_Filled,
 *    Completed, Closed, Cancelled, On_Hold) and remaps legacy values.
 * 2. Adds `published_at` (the "Published Date" the workspace needs).
 * 3. Adds a per-tenant (tenant_id, status) index.
 *
 * Idempotent + multi-tenant: no tenant semantics change; all columns stay on
 * the existing tenant-scoped table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_job_postings', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('closing_date');
            }
        });

        // enum → string (Laravel 12 native change, no doctrine/dbal needed)
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->string('status', 40)->default(JobPostingStatus::DRAFT)->change();
        });

        // Remap legacy status values (Active → Published)
        foreach (JobPostingStatus::LEGACY_MAP as $legacy => $canonical) {
            DB::table('hr_job_postings')->where('status', $legacy)->update(['status' => $canonical]);
        }

        // Backfill published_at for already-live postings that lack it
        DB::table('hr_job_postings')
            ->whereIn('status', JobPostingStatus::LIVE)
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);

        Schema::table('hr_job_postings', function (Blueprint $table) {
            if (! $this->indexExists('hr_job_postings', 'hr_jp_tenant_status_idx')) {
                $table->index(['tenant_id', 'status'], 'hr_jp_tenant_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            if ($this->indexExists('hr_job_postings', 'hr_jp_tenant_status_idx')) {
                $table->dropIndex('hr_jp_tenant_status_idx');
            }
            if (Schema::hasColumn('hr_job_postings', 'published_at')) {
                $table->dropColumn('published_at');
            }
        });
        // status column left as string — reverting to the narrow enum would
        // reject the new lifecycle values other code now depends on.
    }

    private function indexExists(string $table, string $index): bool
    {
        // Driver-agnostic: PRAGMA is SQLite-only and simply throws on MySQL,
        // which used to make every index look absent.
        try {
            return collect(Schema::getIndexes($table))
                ->contains(fn ($i) => strcasecmp($i['name'] ?? '', $index) === 0);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
