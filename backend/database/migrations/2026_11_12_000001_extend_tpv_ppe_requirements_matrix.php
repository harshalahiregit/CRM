<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §18 PPE Matrix — grow the single-dimension (role → PPE) table into the doc's
 * "Job + Hazard + Activity → Required PPE" rule with a class and governance
 * attributes:
 *   hazard / activity          — the risk context (Welding → Arc/Heat → …)
 *   ppe_class                  — mandatory | optional | conditional (only
 *                                mandatory gates the badge; today every rule did)
 *   condition                  — free text describing when a conditional rule applies
 *   replacement_frequency_days — how often the item must be re-issued
 *   verification_required      — the item must be verified, not just issued
 *
 * All nullable / defaulted so existing role→PPE rules keep working unchanged
 * (they default to ppe_class = 'mandatory', preserving today's gate behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_ppe_requirements', function (Blueprint $table) {
            $table->string('hazard', 120)->nullable()->after('scope_value');
            $table->string('activity', 120)->nullable()->after('hazard');
            $table->string('ppe_class', 20)->default('mandatory')->after('activity');
            $table->string('condition', 200)->nullable()->after('ppe_class');
            $table->unsignedInteger('replacement_frequency_days')->nullable()->after('qty');
            $table->boolean('verification_required')->default(false)->after('replacement_frequency_days');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_ppe_requirements', function (Blueprint $table) {
            $table->dropColumn(['hazard', 'activity', 'ppe_class', 'condition', 'replacement_frequency_days', 'verification_required']);
        });
    }
};
