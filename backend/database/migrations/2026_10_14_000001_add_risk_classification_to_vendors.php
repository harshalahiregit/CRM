<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Risk Classification (Sangoe TPV gap report area 2).
 *
 * A FORWARD-LOOKING risk tier (Critical/High/Medium/Low) set at qualification
 * from weighted risk factors — deliberately distinct from the VRS performance
 * scorecard (safety/compliance/workforce → A–D band), which is a HISTORICAL
 * measure. Risk classification drives how closely a vendor is monitored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('risk_level', 16)->nullable()->after('category')->index();
            $table->unsignedTinyInteger('risk_score')->nullable()->after('risk_level');
            $table->json('risk_factors')->nullable()->after('risk_score');
            $table->text('risk_notes')->nullable()->after('risk_factors');
            $table->timestamp('risk_assessed_at')->nullable()->after('risk_notes');
            $table->unsignedBigInteger('risk_assessed_by')->nullable()->after('risk_assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'risk_level', 'risk_score', 'risk_factors',
                'risk_notes', 'risk_assessed_at', 'risk_assessed_by',
            ]);
        });
    }
};
