<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2 of the onboarding wizard captures a TPV-specific profile (DOB, social
 * links, emergency contact, workforce estimate, …) that doesn't belong on the
 * shared Vendor master. Stored as JSON on the onboarding record.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->json('profile')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
