<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a punch has no selfie or no coordinates.
 *
 * hr_attendance already carries check_in_selfie / _latitude / _longitude and the
 * matching check-out columns, filled by the phone app. A punch made from the CRM
 * in a browser left all of them NULL, and a NULL cannot say whether nobody was
 * asked, the camera was missing, or the person refused. Those are three very
 * different facts and HR can only act on the last one.
 *
 * So the reason travels with the record. A punch is still never refused for
 * missing evidence — somebody with a broken webcam has done their part — it is
 * simply recorded as unverified, with the reason attached, which is what lets
 * the register show the handful worth asking about instead of all of them.
 *
 * Additive and nullable: every existing row keeps reading exactly as it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_attendance', 'check_in_verification')) {
                $table->string('check_in_verification', 120)->nullable()->after('check_in_ip');
            }
            if (! Schema::hasColumn('hr_attendance', 'check_out_verification')) {
                $table->string('check_out_verification', 120)->nullable()->after('check_out_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            foreach (['check_in_verification', 'check_out_verification'] as $column) {
                if (Schema::hasColumn('hr_attendance', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
