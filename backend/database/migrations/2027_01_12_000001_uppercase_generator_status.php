<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — the genset state, in the ruled vocabulary (T-06).
 *
 * The tasklist framed this as a choice: "spec says `ON/OFF/UNKNOWN`; ours is
 * richer. Map at the boundary or align." It is both, and the reason is worth
 * writing down.
 *
 * **Aligned in case**, because `generator_status` is a stored state and spec
 * 12.S11 puts those in UPPERCASE — and because STOS-API already spells its
 * three values that way, so the two standards agreed with each other.
 *
 * **Not aligned in vocabulary**, because collapsing to three values would
 * destroy the distinction that matters most on a reefer: a genset in FAULT is
 * not one somebody switched OFF. The load spoils the same way; the person
 * fixing it needs to know which, and so does anyone asking afterwards why it
 * was not noticed. `UNKNOWN` maps to NULL rather than to a value, because "the
 * device did not say" must never be recorded as "the device said off".
 *
 * The mapping lives at the boundary — `VehicleLiveStatus::normaliseGeneratorState()`
 * — so units already in the field keep reporting in lowercase and nothing
 * downstream has to know which dialect a device speaks.
 */
return new class extends Migration
{
    private const MAP = [
        'off' => 'OFF', 'on' => 'ON', 'standby' => 'STANDBY', 'fault' => 'FAULT',
        // Never seen in the column, but it is what the API contract calls the
        // third state and a tenant may have written it in by hand.
        'unknown' => null, 'UNKNOWN' => null,
    ];

    private const TABLES = ['vehicle_live_status', 'telemetry_records'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'generator_status')) {
                continue;
            }

            foreach (self::MAP as $old => $new) {
                DB::table($table)->where('generator_status', $old)
                    ->update(['generator_status' => $new]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'generator_status')) {
                continue;
            }

            foreach (['OFF' => 'off', 'ON' => 'on', 'STANDBY' => 'standby', 'FAULT' => 'fault'] as $new => $old) {
                DB::table($table)->where('generator_status', $new)
                    ->update(['generator_status' => $old]);
            }
        }
    }
};
