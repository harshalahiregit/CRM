<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · Item 1 — Kickoff PDF acknowledgement.
 *
 * Additive: records that the vendor read and accepted the Kickoff document, with
 * the captured context (IP, browser, device). Separate from the shared Kickoff
 * *meeting* engine — this is the Step-1 document gate on the onboarding record.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->string('kickoff_pdf_path')->nullable()->after('work_start_letter_path');
            $table->boolean('acknowledged')->default(false)->after('kickoff_pdf_path');
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged');
            $table->string('acknowledged_ip')->nullable()->after('acknowledged_at');
            $table->string('acknowledged_browser')->nullable()->after('acknowledged_ip');
            $table->string('acknowledged_device')->nullable()->after('acknowledged_browser');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->dropColumn([
                'kickoff_pdf_path', 'acknowledged', 'acknowledged_at',
                'acknowledged_ip', 'acknowledged_browser', 'acknowledged_device',
            ]);
        });
    }
};
