<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable, polymorphic audit trail.
 *
 * `audit_logs` records every meaningful action taken against any model
 * (auditable_type + auditable_id) — starting with HR Manpower Requests and
 * later extensible to Job Postings, Candidates, Interviews, Offers, Onboarding
 * simply by adding the Auditable trait to those models. Actor name/role are
 * snapshotted so the trail stays truthful even if a user is renamed/deleted.
 *
 * Multi-tenant: every row carries tenant_id (row-level isolation, matching the
 * rest of the app) and is indexed for fast per-tenant / per-record reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();

                // Polymorphic subject of the log
                $table->string('auditable_type');
                $table->unsignedBigInteger('auditable_id');

                $table->string('action');                       // Submitted, L1 Approved, Rejected, Sent Back, ...
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_name')->nullable();       // snapshot — survives user rename/delete
                $table->string('actor_role')->nullable();       // snapshot — role/internal_role at action time
                $table->text('comment')->nullable();
                $table->json('metadata')->nullable();           // {level, from, to, ...}
                $table->timestamps();

                $table->index(['auditable_type', 'auditable_id', 'id'], 'audit_logs_subject_idx');
                $table->index(['tenant_id'], 'audit_logs_tenant_idx');
            });
        }

        // ── Backfill: migrate the existing manpower approval history so the
        //    timeline is complete from a single source of truth. ───────────────
        if (Schema::hasTable('hr_approval_history') && DB::table('audit_logs')->count() === 0) {
            $users = DB::table('users')->get()->keyBy('id');

            foreach (DB::table('hr_approval_history')->orderBy('id')->get() as $row) {
                $actor = $row->actor_id ? ($users[$row->actor_id] ?? null) : null;

                DB::table('audit_logs')->insert([
                    'tenant_id'      => $row->tenant_id ?? ($actor->tenant_id ?? null),
                    'auditable_type' => 'App\\Models\\Hr\\HrManpowerRequest',
                    'auditable_id'   => $row->request_id,
                    'action'         => $row->action,
                    'actor_id'       => $row->actor_id,
                    'actor_name'     => $actor->name ?? null,
                    'actor_role'     => $actor ? ($actor->internal_role ?: $actor->role) : null,
                    'comment'        => $row->remarks ?? null,
                    'metadata'       => json_encode(array_filter([
                        'level' => ($row->level ?? null) && $row->level !== 'General' ? $row->level : null,
                    ])) ?: null,
                    'created_at'     => $row->created_at,
                    'updated_at'     => $row->updated_at ?? $row->created_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
