<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three-state attendance + a per-attendee remark.
 *
 * `attended` (boolean) is deliberately KEPT and kept in sync: the PDF template,
 * the portal and any existing API consumer still read it, and this must not
 * change what they see. attendance_status is the richer truth; attended is the
 * boolean projection of it (Present or Late => true).
 *
 * Backfill sets Present where attended = 1 and leaves everything else NULL
 * rather than writing 'Absent'. NULL means "not marked yet", which is what an
 * unmarked row actually is — asserting Absent would invent a fact about every
 * meeting recorded before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_attendees', function (Blueprint $table) {
            $table->string('attendance_status', 16)->nullable()->after('attended');
            $table->text('remark')->nullable()->after('attendance_status');
        });

        DB::table('kickoff_attendees')->where('attended', true)->update(['attendance_status' => 'Present']);
    }

    public function down(): void
    {
        Schema::table('kickoff_attendees', function (Blueprint $table) {
            $table->dropColumn(['attendance_status', 'remark']);
        });
    }
};
