<?php

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — ownership gets its two missing values and goes UPPERCASE (T-03),
 * and the normalised plate is backfilled (D-141).
 *
 * ── OWNERSHIP (T-03) ──────────────────────────────────────────────────────
 * `vehicles.ownership_type` held four lowercase values — owned/leased/attached/
 * market — where STOS-FLEET §10 names six: Owned, Financed, Leased, Contracted,
 * Attached, Other. This maps the stored values onto the full UPPERCASE set that
 * `Vehicle::OWNERSHIPS` now declares, and moves the column default with them.
 *
 * `market` → OTHER, not CONTRACTED: it was never in §10, no row has ever held
 * it (measured before writing this), and spot-market hire is a one-off, not a
 * standing contract. OTHER is §10's own catch-all and the honest home for it.
 *
 * FINANCED and CONTRACTED are added to the vocabulary, not backfilled onto rows:
 * the masters move flattened them to owned/attached and that information is gone
 * from the moved rows. What this migration fixes is that no FUTURE onboarding or
 * re-migrate flattens them again. (The move's own `mapOwnership` still flattens;
 * that is P1's file and D-154 in the registry asks him to stop now that Fleet
 * can hold the values.)
 *
 * ── NORMALISED PLATE (D-141) ──────────────────────────────────────────────
 * `registration_normalized` was added with an index by the D-62 union and never
 * written on save, so it sat empty and the plate search normalised every row at
 * query time to cope. The model now derives it on save; this fills the rows that
 * predate that. Recomputed from `registration_number`, not trusted.
 */
return new class extends Migration
{
    /** Old value (any case) => ruled UPPERCASE value. */
    private const MAP = [
        'OWNED'      => Vehicle::OWNERSHIP_OWNED,
        'LEASED'     => Vehicle::OWNERSHIP_LEASED,
        'ATTACHED'   => Vehicle::OWNERSHIP_ATTACHED,
        'FINANCED'   => Vehicle::OWNERSHIP_FINANCED,
        'CONTRACTED' => Vehicle::OWNERSHIP_CONTRACTED,
        'OTHER'      => Vehicle::OWNERSHIP_OTHER,
        'MARKET'     => Vehicle::OWNERSHIP_OTHER,
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $ruled) {
            // Case-insensitive match: rows arrived lowercase from Fleet and
            // UPPERCASE from the legacy table, and both must land on the enum.
            DB::table('vehicles')
                ->whereRaw('UPPER(ownership_type) = ?', [$old])
                ->update(['ownership_type' => $ruled]);
        }

        // Anything the map did not name — a value no version of this field ever
        // legitimately held — is an arrangement outside the five, which is what
        // OTHER means. Better than leaving a lowercase orphan the enum rejects.
        DB::table('vehicles')
            ->whereNotIn('ownership_type', Vehicle::OWNERSHIPS)
            ->update(['ownership_type' => Vehicle::OWNERSHIP_OTHER]);

        // D-141 — backfill the normalised plate the model now maintains.
        DB::table('vehicles')
            ->whereNotNull('registration_number')
            ->where('registration_number', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('vehicles')->where('id', $row->id)->update([
                        'registration_normalized' => Vehicle::normaliseRegistration((string) $row->registration_number),
                    ]);
                }
            });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('ownership_type', 20)->default(Vehicle::OWNERSHIP_OWNED)->change();
        });
    }

    public function down(): void
    {
        // Lowercase the three values that existed before, and fold the two new
        // ones onto their pre-T-03 flattening so the old enum still accepts every
        // row. This does not restore information the move already lost.
        $revert = [
            Vehicle::OWNERSHIP_OWNED      => 'owned',
            Vehicle::OWNERSHIP_LEASED     => 'leased',
            Vehicle::OWNERSHIP_ATTACHED   => 'attached',
            Vehicle::OWNERSHIP_FINANCED   => 'owned',
            Vehicle::OWNERSHIP_CONTRACTED => 'attached',
            Vehicle::OWNERSHIP_OTHER      => 'owned',
        ];

        foreach ($revert as $ruled => $old) {
            DB::table('vehicles')->where('ownership_type', $ruled)->update(['ownership_type' => $old]);
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('ownership_type', 20)->default('owned')->change();
        });
    }
};
