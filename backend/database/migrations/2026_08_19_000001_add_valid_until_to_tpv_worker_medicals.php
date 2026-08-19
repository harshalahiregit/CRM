<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Medical fitness certificates have a currency window (Doc_4 §8). Without an
 * explicit expiry the gate could only *warn* on a stale exam and never deny, and
 * badge activation ignored expiry entirely. `valid_until` makes the currency
 * window a first-class, enforceable field: an expired medical blocks activation
 * and is denied at the gate, exactly like an expired badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tpv_worker_medicals', 'valid_until')) {
            Schema::table('tpv_worker_medicals', function (Blueprint $table) {
                $table->date('valid_until')->nullable()->after('exam_date');
            });
        }

        // Backfill existing records: a medical is conventionally valid for one year
        // from the examination date. Done in PHP so it is driver-agnostic (SQLite,
        // MySQL, Postgres). Rows with no exam_date are left null (unknown currency).
        DB::table('tpv_worker_medicals')
            ->whereNull('valid_until')
            ->whereNotNull('exam_date')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('tpv_worker_medicals')
                        ->where('id', $row->id)
                        ->update(['valid_until' => Carbon::parse($row->exam_date)->addYear()->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tpv_worker_medicals', 'valid_until')) {
            Schema::table('tpv_worker_medicals', function (Blueprint $table) {
                $table->dropColumn('valid_until');
            });
        }
    }
};
