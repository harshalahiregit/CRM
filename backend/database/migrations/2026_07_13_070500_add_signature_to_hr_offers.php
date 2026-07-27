<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Digital acceptance signature on the offer portal. IP / device / browser /
     * timestamp already exist (accepted_ip, accepted_device, accepted_browser,
     * accepted_at); this adds the typed name and the optional drawn signature.
     */
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->string('accepted_name')->nullable()->after('accepted_browser');
            $table->longText('accepted_signature')->nullable()->after('accepted_name');
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropColumn(['accepted_name', 'accepted_signature']);
        });
    }
};
