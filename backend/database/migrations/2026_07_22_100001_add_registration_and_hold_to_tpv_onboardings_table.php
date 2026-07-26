<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · Item 5 — Final Approval with Registration Number.
 *
 * Additive only: adds the issued Registration Number (`TPV-YYYY-NNNNN`) and the
 * hold reason to the existing onboarding workflow table. Nothing is removed.
 * The number is unique per tenant; nullable so historic rows are unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->string('registration_number')->nullable()->after('approved_by');
            $table->text('hold_reason')->nullable()->after('remarks');

            $table->unique(['tenant_id', 'registration_number']);
        });
    }

    public function down(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'registration_number']);
            $table->dropColumn(['registration_number', 'hold_reason']);
        });
    }
};
