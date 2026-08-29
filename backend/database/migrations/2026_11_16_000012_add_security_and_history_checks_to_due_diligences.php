<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §7 Due-Diligence — the three checks the doc names that the initial table
 * missed: previous performance, incident history and compliance history. Each is
 * a status column with the same Pending/Verified/Failed/Not_Applicable states as
 * the existing checks, so deriveStatus() rolls them into the overall outcome.
 * Additive — existing records default to Pending like the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_due_diligences', function (Blueprint $table) {
            $table->string('previous_performance', 20)->default('Pending')->after('reference_check');
            $table->string('incident_history', 20)->default('Pending')->after('previous_performance');
            $table->string('compliance_history', 20)->default('Pending')->after('incident_history');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_due_diligences', function (Blueprint $table) {
            $table->dropColumn(['previous_performance', 'incident_history', 'compliance_history']);
        });
    }
};
