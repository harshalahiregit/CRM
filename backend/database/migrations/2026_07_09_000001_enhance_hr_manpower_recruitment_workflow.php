<?php

use App\Support\Hr\ManpowerRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR Recruitment Module enhancement.
 *
 * 1. Adds the extended Manpower Request hiring fields (business unit, project,
 *    salary range, skills, JD, target joining date, etc.).
 * 2. Reconciles the L1/L2 approval columns that were previously added to the
 *    live DB via ad-hoc scripts — so a fresh `migrate` reproduces the schema
 *    the code depends on. Every add is guarded with hasColumn() to stay
 *    idempotent on databases where those columns already exist.
 * 3. Normalises status to a string column and remaps the legacy status
 *    vocabulary (Pending / Pending_L1 / Pending_L2 / Approved) to the new
 *    canonical workflow states.
 * 4. Wires the Manpower Request ⇄ Job Posting linkage for JD conversion.
 *
 * Multi-tenant: all columns live on the existing tenant-scoped tables; no
 * tenant isolation semantics are changed. An index on (tenant_id, status)
 * is added to keep the per-tenant queue/dashboard queries fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            // ── Extended hiring information (Phase 1, section 1) ──────────────
            if (! Schema::hasColumn('hr_manpower_requests', 'business_unit')) {
                $table->string('business_unit', 150)->nullable()->after('tenant_id');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'project')) {
                $table->string('project', 150)->nullable()->after('department');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'location')) {
                $table->string('location', 150)->nullable()->after('project');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'employee_level')) {
                $table->string('employee_level', 60)->nullable()->after('position_title');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'experience_required')) {
                $table->string('experience_required', 100)->nullable()->after('employee_level');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'salary_min')) {
                $table->decimal('salary_min', 12, 2)->nullable()->after('experience_required');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'salary_max')) {
                $table->decimal('salary_max', 12, 2)->nullable()->after('salary_min');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'required_skills')) {
                $table->json('required_skills')->nullable()->after('salary_max');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'job_description')) {
                $table->text('job_description')->nullable()->after('required_skills');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'target_joining_date')) {
                $table->date('target_joining_date')->nullable()->after('required_by_date');
            }

            // ── L1/L2 approval columns (reconcile ad-hoc live schema) ─────────
            if (! Schema::hasColumn('hr_manpower_requests', 'l1_approver_id')) {
                $table->unsignedBigInteger('l1_approver_id')->nullable();
                $table->timestamp('l1_approved_at')->nullable();
                $table->text('l1_remarks')->nullable();
                $table->string('l1_status', 20)->default('pending');
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'l2_approver_id')) {
                $table->unsignedBigInteger('l2_approver_id')->nullable();
                $table->timestamp('l2_approved_at')->nullable();
                $table->text('l2_remarks')->nullable();
                $table->string('l2_status', 20)->default('pending');
            }

            // ── Workflow audit timestamps + JD linkage ───────────────────────
            if (! Schema::hasColumn('hr_manpower_requests', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'converted_at')) {
                $table->timestamp('converted_at')->nullable();
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'posted_at')) {
                $table->timestamp('posted_at')->nullable();
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'closed_at')) {
                $table->timestamp('closed_at')->nullable();
            }
            if (! Schema::hasColumn('hr_manpower_requests', 'job_posting_id')) {
                $table->unsignedBigInteger('job_posting_id')->nullable();
            }
        });

        // ── Normalise status column to a string (was enum on fresh installs) ─
        // Laravel 12 supports native ->change() on SQLite/MySQL without dbal.
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->string('status', 40)->default(ManpowerRequestStatus::DRAFT)->change();
        });

        // ── Remap legacy status values to the new canonical vocabulary ───────
        foreach (ManpowerRequestStatus::LEGACY_MAP as $legacy => $canonical) {
            DB::table('hr_manpower_requests')->where('status', $legacy)->update(['status' => $canonical]);
        }

        // ── Per-tenant query index (queue + dashboard) ───────────────────────
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            if (! $this->indexExists('hr_manpower_requests', 'hr_mr_tenant_status_idx')) {
                $table->index(['tenant_id', 'status'], 'hr_mr_tenant_status_idx');
            }
        });

        // ── Approval history: tenant-scope it (was missing tenant_id) ────────
        Schema::table('hr_approval_history', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_approval_history', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            if ($this->indexExists('hr_manpower_requests', 'hr_mr_tenant_status_idx')) {
                $table->dropIndex('hr_mr_tenant_status_idx');
            }
            foreach ([
                'business_unit', 'project', 'location', 'employee_level', 'experience_required',
                'salary_min', 'salary_max', 'required_skills', 'job_description', 'target_joining_date',
                'submitted_at', 'converted_at', 'posted_at', 'closed_at', 'job_posting_id',
            ] as $col) {
                if (Schema::hasColumn('hr_manpower_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        // L1/L2 columns and status type are intentionally left in place — they
        // predate this migration (added out-of-band) and other code depends on them.
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
