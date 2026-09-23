<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — the last three lowercase enums (T-58).
 *
 * Spec 12.S11 splits the naming: **database enums and state-machine states are
 * UPPERCASE; API blocker codes and machine reasons are lowercase snake_case.**
 * Vehicles adopted it in `2027_01_07_000001`. Person 1 converted the half his
 * board consumes and left the internal three to me: the workshop job card, the
 * genset register and tyre fitments.
 *
 * ── THIS IS NOT COSMETIC, AND THERE IS ALREADY A CASUALTY ─────────────────
 * `GensetService::fit()` guards against fitting a unit to a scrapped truck:
 *
 *     if ($vehicle->status === 'retired')
 *
 * `vehicles.status` has held `RETIRED` since the January vocabulary change, so
 * that comparison has been false every time it has ever run. A genset can be
 * fitted to a retired vehicle today, and nothing says so — no error, no log
 * line. Two spellings of one state is exactly how a guard stops guarding, which
 * is the argument the vehicle migration made and the proof of it.
 *
 * Fixed in the same change as the rename, because fixing one without the other
 * leaves the next person to find the same trap.
 *
 * ── NO COLUMN WIDENING NEEDED ─────────────────────────────────────────────
 * The longest new value is `AWAITING_PARTS` at 14 characters and every column
 * is `VARCHAR(20)`. Only the DEFAULTS move, because a default in the old
 * vocabulary would quietly reintroduce a lowercase row on the next insert that
 * omits the column.
 */
return new class extends Migration
{
    /** table => [column default, old => new]. */
    private const MAP = [
        'maintenance_jobs' => [
            'default' => 'OPEN',
            'values' => [
                'open' => 'OPEN',
                'in_progress' => 'IN_PROGRESS',
                'awaiting_parts' => 'AWAITING_PARTS',
                'testing' => 'TESTING',
                'qc' => 'QC',
                'completed' => 'COMPLETED',
                'cancelled' => 'CANCELLED',
            ],
        ],
        'gensets' => [
            'default' => 'ACTIVE',
            'values' => [
                'active' => 'ACTIVE',
                'in_maintenance' => 'IN_MAINTENANCE',
                'idle' => 'IDLE',
                'retired' => 'RETIRED',
            ],
        ],
        'tyre_fitments' => [
            'default' => 'FITTED',
            'values' => [
                'in_stock' => 'IN_STOCK',
                'fitted' => 'FITTED',
                'removed' => 'REMOVED',
                'retreaded' => 'RETREADED',
                'scrapped' => 'SCRAPPED',
            ],
        ],
    ];

    public function up(): void
    {
        foreach (self::MAP as $table => $spec) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($spec) {
                $t->string('status', 20)->default($spec['default'])->change();
            });

            foreach ($spec['values'] as $old => $new) {
                DB::table($table)->where('status', $old)->update(['status' => $new]);
            }

            $this->reportStragglers($table, $spec['values']);
        }
    }

    /**
     * Anything not in the map is a value nobody declared.
     *
     * Left alone and reported rather than folded into the default: silently
     * turning an unknown job-card state into OPEN would put a truck back in the
     * workshop queue that nobody put there, and turning one into COMPLETED
     * would release a truck nobody signed off.
     */
    private function reportStragglers(string $table, array $values): void
    {
        $unknown = DB::table($table)
            ->whereNotIn('status', array_values($values))
            // `rows` is a reserved word in MySQL 8.0 and this query is
            // unquoted, so the straggler report — not the data change — halted
            // the whole migration chain partway through. Backticked. D-132.
            ->select('status', DB::raw('count(*) as `rows`'))
            ->groupBy('status')->get();

        if ($unknown->isEmpty()) {
            return;
        }

        Log::channel('stos')->warning('Rows left in an undeclared status by the uppercase migration', [
            'defect' => 'T-58', 'table' => $table,
            'statuses' => $unknown->pluck('rows', 'status')->all(),
        ]);
    }

    public function down(): void
    {
        foreach (self::MAP as $table => $spec) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }

            foreach ($spec['values'] as $old => $new) {
                DB::table($table)->where('status', $new)->update(['status' => $old]);
            }

            Schema::table($table, function (Blueprint $t) use ($spec) {
                $t->string('status', 20)->default(strtolower($spec['default']))->change();
            });
        }
    }
};
