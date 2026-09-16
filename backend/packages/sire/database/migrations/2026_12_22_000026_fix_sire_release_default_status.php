<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — `sire_releases.status` defaulted to 'planned', which is not a release
 * status.
 *
 * SireReleaseStatus::ALL is blocked · ready · approved · released · cancelled ·
 * rolled_back. 'planned' predates the governed lifecycle and survived it, so
 * every release created without an explicit status landed somewhere the state
 * machine does not recognise: no TRANSITIONS entry names it as a `from`, which
 * means such a release could never be approved, shipped or even cancelled. It
 * looked entirely normal in the register.
 *
 * BLOCKED is the correct starting point — a release begins closed and its gates
 * open it, which is exactly what the gate evaluator does on the next read.
 *
 * A separate migration rather than an edit to 000011: that one has run
 * everywhere already.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sire_releases')) {
            return;
        }

        // Repair the rows before changing the default, so nothing is left behind
        // in a status the lifecycle cannot move.
        DB::table('sire_releases')->where('status', 'planned')->update(['status' => 'blocked']);

        Schema::table('sire_releases', function (Blueprint $table) {
            $table->string('status')->default('blocked')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sire_releases')) {
            return;
        }

        Schema::table('sire_releases', function (Blueprint $table) {
            $table->string('status')->default('planned')->change();
        });
    }
};
