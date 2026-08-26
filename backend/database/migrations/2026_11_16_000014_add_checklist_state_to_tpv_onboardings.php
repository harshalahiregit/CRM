<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10 — the general onboarding checklist (beyond documents) that gates activation.
 * This stores which of the resolved checklist items an admin has ticked for a
 * given onboarding, as {item => true}. When the effective checklist's general
 * block has gates_activation = true, approve() refuses to activate until every
 * resolved item is ticked here. Nullable & additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->json('checklist_state')->nullable()->after('profile');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            $table->dropColumn('checklist_state');
        });
    }
};
