<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: older interview rounds were created without a tenant_id (the value
 * wasn't set on insert). That made them invisible to any query filtering on
 * hr_interview_rounds.tenant_id directly — notably the HR dashboard counters —
 * even though they belong to a real tenant via their candidate. Backfill the
 * tenant_id from each round's candidate so every counter and filter is consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_interview_rounds')
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', 0)
            ->get(['id', 'candidate_id'])
            ->each(function ($round) {
                $tenantId = DB::table('hr_candidates')->where('id', $round->candidate_id)->value('tenant_id');
                if ($tenantId) {
                    DB::table('hr_interview_rounds')->where('id', $round->id)->update(['tenant_id' => $tenantId]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data backfill — no-op.
    }
};
