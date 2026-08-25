<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule 6 — "No Permit, No High-Risk Work" (Sangoe TPV §19/§36). An activity can be
 * flagged as high-risk work that requires a valid Permit-to-Work; `permit_type`
 * optionally pins WHICH permit type (Hot Work, Confined Space…). Defaults keep
 * every existing activity non-permit-gated, so behaviour is unchanged until an
 * admin marks an activity high-risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_activities', function (Blueprint $table) {
            $table->boolean('requires_permit')->default(false)->after('required_competency');
            $table->string('permit_type', 40)->nullable()->after('requires_permit');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_activities', function (Blueprint $table) {
            $table->dropColumn(['requires_permit', 'permit_type']);
        });
    }
};
