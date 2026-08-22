<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase meeting participant detail (Meeting.docx §5) + 6-state attendance (§6).
 * Adds designation, phone and internal/external side, plus an attendance_status
 * (Present/Absent/Late/Excused/Online/Offline) alongside the existing boolean
 * `attended`. Back-fills attendance_status from the boolean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_kickoff_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_kickoff_participants', 'designation')) {
                $table->string('designation', 120)->nullable()->after('organisation');
            }
            if (! Schema::hasColumn('purchase_kickoff_participants', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }
            if (! Schema::hasColumn('purchase_kickoff_participants', 'side')) {
                $table->string('side', 16)->nullable()->after('role'); // internal | external
            }
            if (! Schema::hasColumn('purchase_kickoff_participants', 'attendance_status')) {
                $table->string('attendance_status', 16)->nullable()->after('attended');
            }
        });

        DB::table('purchase_kickoff_participants')->whereNotNull('attended')
            ->update(['attendance_status' => DB::raw("CASE WHEN attended = 1 THEN 'Present' ELSE 'Absent' END")]);
    }

    public function down(): void
    {
        Schema::table('purchase_kickoff_participants', function (Blueprint $table) {
            foreach (['designation', 'phone', 'side', 'attendance_status'] as $col) {
                if (Schema::hasColumn('purchase_kickoff_participants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
