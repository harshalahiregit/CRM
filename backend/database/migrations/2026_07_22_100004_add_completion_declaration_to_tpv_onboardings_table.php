<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · Item 4 — Final Confirmation declaration.
 *
 * Additive: records the Step-5 declaration and the completion context (IP,
 * browser, device) captured when the vendor finishes onboarding. The workflow
 * status transition itself is unchanged — these columns sit alongside it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->timestamp('declaration_accepted_at')->nullable()->after('acknowledged_device');
            $table->boolean('onboarding_complete')->default(false)->after('declaration_accepted_at');
            $table->timestamp('completed_at')->nullable()->after('onboarding_complete');
            $table->string('completed_ip')->nullable()->after('completed_at');
            $table->string('completed_browser')->nullable()->after('completed_ip');
            $table->string('completed_device')->nullable()->after('completed_browser');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->dropColumn([
                'declaration_accepted_at', 'onboarding_complete', 'completed_at',
                'completed_ip', 'completed_browser', 'completed_device',
            ]);
        });
    }
};
