<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-4 — legal-grade capture on the medical sign. The examiner's signature was
 * already stored; the senior wants IP + geolocation + a photo alongside it so the
 * record is verifiable and unchallengeable. Server stamps system_ip; the client
 * sends geo_location (lat,long) and an optional capture photo. This same trio is
 * the pattern training (P2-2) and permits (P1-2) will reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->string('system_ip', 45)->nullable()->after('signature_path');
            $table->string('geo_location', 120)->nullable()->after('system_ip');
            $table->string('capture_photo_path')->nullable()->after('geo_location');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropColumn(['system_ip', 'geo_location', 'capture_photo_path']);
        });
    }
};
