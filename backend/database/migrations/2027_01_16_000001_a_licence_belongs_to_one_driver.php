<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — one licence, one driver. D-145.
 *
 * ── THE GUARD THAT WENT MISSING IN THE MOVE ───────────────────────────────
 * `transport_drivers` carried `unique(tenant_id, licence_normalized)`, and a
 * request class probed it so the refusal read as a sentence. When the masters
 * moved into Fleet (2027_01_02) the licence columns came across — INCLUDING
 * `licence_normalized`, so the legacy value would survive — and the index did
 * not. That is worse than a plain omission: the column has sat there since,
 * holding whatever the move copied into it, while every save through Fleet
 * wrote `licence_number` and left it alone. A column that looks like a guard,
 * is not one, and drifts.
 *
 * `driver_profiles` has exactly one unique index, `(company_id, source,
 * source_id)`, which says *one profile per person in the directory* — a
 * different claim entirely.
 *
 * The result, measured on master before this migration: two profiles could
 * hold the same licence number, and nothing anywhere objected. Person 1 found
 * it by asking who enforced the rule after the move, and left the legacy test
 * red rather than delete it. This is the answer to that question.
 *
 * ── WHY A COLUMN AND NOT A GENERATED ONE ──────────────────────────────────
 * The normalisation is a regex — "RJ14 20110012345", "RJ-14-2011-0012345" and
 * "rj1420110012345" are one licence. MySQL 8 could express that in a stored
 * generated column; SQLite, which the suite runs on, cannot. So it is a plain
 * column written on save, which is how `transport_drivers` did it and what the
 * rest of this module already reads like.
 *
 * ── WHY NULLS ARE LEFT ALONE ──────────────────────────────────────────────
 * The index is over `(company_id, licence_normalized)` and the column is
 * nullable. Both engines allow many NULLs under a unique index, which is the
 * behaviour wanted: a driver whose licence has not been recorded yet is a
 * normal, expected state — plenty of profiles exist to hold a medical date or
 * a vehicle assignment before anyone types a licence. Only a RECORDED licence
 * is claimed to be unique.
 *
 * ── A DUPLICATE STOPS THE MIGRATION, BY NAME ──────────────────────────────
 * If two profiles already share a licence, this refuses and names them. It
 * does not pick a winner: two profiles with one licence means two records for
 * one human being, and which is the real one is a question about people, not
 * about data. Refusing here costs a deploy; guessing costs a driver's history.
 * MySQL would refuse anyway — with "Duplicate entry '…' for key '…'", which
 * names a value and no person.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Recompute, do not trust. The column exists already — 2027_01_02
        // brought it across with the move so the legacy value would survive —
        // but nothing has written it since: every save through Fleet set
        // `licence_number` and left this untouched. So a row's normalised
        // value is either a migrated one or NULL, and neither is necessarily
        // what today's licence number normalises to. Deriving all of them from
        // `licence_number` is the only way the index guards the truth.
        //
        // Chunked: a licence register is small today and will not stay that way.
        DB::table('driver_profiles')
            ->whereNotNull('licence_number')
            ->where('licence_number', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('driver_profiles')->where('id', $row->id)->update([
                        'licence_normalized' => self::normalise((string) $row->licence_number),
                    ]);
                }
            });

        // And the other direction: a row with no licence number must not keep
        // a normalised value from before it was cleared. Left behind, it would
        // occupy a slot under the index that no driver's licence claims.
        DB::table('driver_profiles')
            ->where(fn ($q) => $q->whereNull('licence_number')->orWhere('licence_number', ''))
            ->whereNotNull('licence_normalized')
            ->update(['licence_normalized' => null]);

        $this->refuseOnDuplicates();

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unique(['company_id', 'licence_normalized'], 'driver_profiles_company_licence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // The index only. `licence_normalized` is 2027_01_02's column and
            // dropping it here would take the migrated legacy values with it.
            $table->dropUnique('driver_profiles_company_licence_unique');
        });
    }

    /**
     * Same rule as `TransportDriver::normalizeLicence()`, and deliberately not
     * a format validator: licence formats vary by issuing state and by decade,
     * and a regex strict enough to be useful would reject real drivers.
     */
    private static function normalise(string $licence): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($licence))) ?? '';
    }

    private function refuseOnDuplicates(): void
    {
        $clashes = DB::table('driver_profiles')
            ->select('company_id', 'licence_normalized', DB::raw('COUNT(*) AS held_by'))
            ->whereNotNull('licence_normalized')
            ->where('licence_normalized', '<>', '')
            ->groupBy('company_id', 'licence_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($clashes->isEmpty()) {
            return;
        }

        $lines = $clashes->map(function ($clash) {
            $refs = DB::table('driver_profiles')
                ->where('company_id', $clash->company_id)
                ->where('licence_normalized', $clash->licence_normalized)
                ->get(['source', 'source_id'])
                ->map(fn ($row) => $row->source.':'.$row->source_id)
                ->implode(', ');

            return '  licence '.$clash->licence_normalized.' in workspace '.$clash->company_id.' is held by '.$refs;
        })->implode("\n");

        throw new RuntimeException(
            "A licence number identifies one person, and these are recorded against more than one driver:\n"
            .$lines
            ."\n\nDecide which profile is the real driver and clear the licence from the others, then run this again."
        );
    }
};
