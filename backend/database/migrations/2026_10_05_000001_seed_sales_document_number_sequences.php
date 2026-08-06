<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the Document Numbering Engine's counters from the numbers the Sales
 * module has ALREADY issued locally.
 *
 * Without this, the first allocation after an admin enables 'proposal' would
 * start at starting_number (1) and hand out PROP-2026-001 — a reference that
 * already exists and is protected by a UNIQUE index, so the save would fail.
 *
 * Seeds one counter per tenant + document type + period, set to the highest
 * sequence already used in that period. Idempotent: existing counter rows are
 * raised to the local high-water mark, never lowered, so re-running cannot
 * rewind a sequence that the engine has since advanced.
 *
 * PERIOD KEY FORMAT — must match the engine exactly or the seed is ignored and
 * the first allocation restarts at 1 (reissuing a live reference). All three
 * types reset yearly, and ResetStrategyRegistry::periodKeyFor() composes
 * `YearlyResetStrategy::periodKey()` with the config epoch:
 *     'Y' . <YYYY> . ':' . <epoch>        e.g. "Y2026:0"
 * Epoch is 0 for a workspace that has never used the engine's manual reset,
 * which is exactly the case this backfill exists for. Seeding only the CURRENT
 * period is intentional: a later year should legitimately restart at 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_number_sequences')) {
            return; // numbering engine not installed in this workspace
        }

        $this->seed('proposals', 'reference_no', 'PROP-', 'proposal', null);
        $this->seed('estimates', 'reference', 'EST-', 'estimate', 'estimate');
        $this->seed('estimates', 'reference', 'PI-', 'proforma_invoice', 'proforma');
    }

    /**
     * @param string|null $estimateType  restricts to one side of the EST-/PI- split
     */
    private function seed(string $table, string $column, string $prefix, string $documentType, ?string $estimateType): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)
            ->when($estimateType, fn ($q) => $q->where('estimate_type', $estimateType))
            ->where($column, 'like', $prefix.'%')
            ->whereNotNull('tenant_id')
            ->get(['tenant_id', $column]);

        // tenant_id => period_key => highest sequence seen
        $high = [];
        foreach ($rows as $row) {
            // <PREFIX><YYYY>-<NNN>
            if (! preg_match('~^'.preg_quote($prefix, '~').'(\d{4})-(\d+)$~', (string) $row->{$column}, $m)) {
                continue;
            }
            $tenantId = (int) $row->tenant_id;
            $period   = $m[1];
            $seq      = (int) $m[2];

            // Engine's composed period key (see class docblock).
            $key = 'Y'.$period.':0';
            $high[$tenantId][$key] = max($high[$tenantId][$key] ?? 0, $seq);
        }

        foreach ($high as $tenantId => $periods) {
            foreach ($periods as $periodKey => $seq) {
                $existing = DB::table('document_number_sequences')
                    ->where('tenant_id', $tenantId)
                    ->where('document_type', $documentType)
                    ->where('period_key', (string) $periodKey)
                    ->first();

                if (! $existing) {
                    DB::table('document_number_sequences')->insert([
                        'tenant_id'        => $tenantId,
                        'document_type'    => $documentType,
                        'period_key'       => (string) $periodKey,
                        'current_sequence' => $seq,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                } elseif ((int) $existing->current_sequence < $seq) {
                    // Raise only — never rewind a sequence the engine has advanced.
                    DB::table('document_number_sequences')
                        ->where('id', $existing->id)
                        ->update(['current_sequence' => $seq, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_number_sequences')) {
            return;
        }

        DB::table('document_number_sequences')
            ->whereIn('document_type', ['proposal', 'estimate', 'proforma_invoice'])
            ->delete();
    }
};
