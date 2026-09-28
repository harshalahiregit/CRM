<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the punch happened, who took it, and from what address.
 *
 * The app has always sent this. Clock-in is a multipart request carrying
 * `latitude`, `longitude` and a `selfie` file — a punch is refused outright
 * without the photo ("Selfie is required."). The CRM validated the coordinates
 * and then dropped them, wrote the selfie to disk and threw the path away, and
 * never looked at the IP at all. So the evidence a punch is meant to carry was
 * being collected from the employee and discarded on arrival, and the attendance
 * register had nothing to show but a time.
 *
 * Separate in/out columns rather than one set: a clock-out happens hours later
 * and somewhere else, and "left from the site" is exactly the question this data
 * exists to answer.
 *
 * Coordinates are stored as strings, matching what the app sends and what the
 * request already validated them as. A decimal cast would round them and the
 * point of a location is that it is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->string('check_in_latitude', 40)->nullable()->after('check_in');
            $table->string('check_in_longitude', 40)->nullable()->after('check_in_latitude');
            // Reverse-geocoded when available; the coordinates remain the record.
            $table->string('check_in_address', 255)->nullable()->after('check_in_longitude');
            $table->string('check_in_selfie', 255)->nullable()->after('check_in_address');
            // 45 fits an IPv6 address in full.
            $table->string('check_in_ip', 45)->nullable()->after('check_in_selfie');

            $table->string('check_out_latitude', 40)->nullable()->after('check_out');
            $table->string('check_out_longitude', 40)->nullable()->after('check_out_latitude');
            $table->string('check_out_address', 255)->nullable()->after('check_out_longitude');
            $table->string('check_out_selfie', 255)->nullable()->after('check_out_address');
            $table->string('check_out_ip', 45)->nullable()->after('check_out_selfie');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_latitude', 'check_in_longitude', 'check_in_address',
                'check_in_selfie', 'check_in_ip',
                'check_out_latitude', 'check_out_longitude', 'check_out_address',
                'check_out_selfie', 'check_out_ip',
            ]);
        });
    }
};
