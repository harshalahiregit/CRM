<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — a driver's medical, and the difference between away and gone
 * (T-41 / T-42).
 *
 * ── T-41: `medical_expiry` ────────────────────────────────────────────────
 * CMP §22 lists the medical certificate beside the licence as a driver
 * document, and T-43 already files and versions it. It gated nothing, because
 * there was no column for a verified certificate to project onto. This is that
 * column, and it completes the pair: `driving_license` → `licence_expiry`,
 * `medical_certificate` → `medical_expiry`.
 *
 * ── T-42: ON_LEAVE IS NOT INACTIVE ────────────────────────────────────────
 * The register had one value, `inactive`, doing two jobs. "On leave until the
 * 14th" and "no longer works here" are different facts with different
 * consequences: the first is a gap in a roster, the second is a person who
 * should stop appearing in one. Rostering with them merged means either
 * chasing someone who left or writing off someone who is back on Monday.
 *
 * Both values now exist. Nothing is guessed: existing `inactive` rows STAY
 * INACTIVE, because that is what was recorded, and a row saying "gone" is the
 * safer of the two to be wrong about — it keeps somebody out of a roster rather
 * than putting them into one.
 *
 * ── AND THE VOCABULARY GOES UPPERCASE WITH THE REST ───────────────────────
 * `driver_profiles.status` is a database enum, so spec 12.S11 applies to it
 * exactly as it does to the three converted in T-58. Doing it in the same
 * breath as adding a value, rather than leaving the last lowercase enum in the
 * module to be found later.
 */
return new class extends Migration
{
    /** Old value => ruled value. */
    private const MAP = [
        'available' => 'AVAILABLE',
        'on_trip'   => 'ON_TRIP',
        'suspended' => 'SUSPENDED',
        'inactive'  => 'INACTIVE',
    ];

    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('driver_profiles', 'medical_expiry')) {
                // Nullable and with no default: a driver whose medical has not
                // been captured is UNKNOWN, which is a different fact from
                // expired and is judged differently. See DriverService.
                $table->date('medical_expiry')->nullable()->after('licence_expiry');
            }
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('status', 20)->default('AVAILABLE')->change();
        });

        foreach (self::MAP as $old => $new) {
            DB::table('driver_profiles')->where('status', $old)->update(['status' => $new]);
        }

        // A blank status is a row that predates the column having a default.
        // Filled rather than left, because every read treats null as "not
        // onboarded" and blocks the person.
        DB::table('driver_profiles')->whereNull('status')->update(['status' => 'AVAILABLE']);
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            // ON_LEAVE has no lowercase original. It folds back into the value
            // it was split out of, which is where it came from.
            DB::table('driver_profiles')->where('status', $new)->update(['status' => $old]);
        }

        DB::table('driver_profiles')->where('status', 'ON_LEAVE')->update(['status' => 'inactive']);

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->change();

            if (Schema::hasColumn('driver_profiles', 'medical_expiry')) {
                $table->dropColumn('medical_expiry');
            }
        });
    }
};
