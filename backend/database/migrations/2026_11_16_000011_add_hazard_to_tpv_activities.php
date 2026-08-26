<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §18 — give an activity an explicit hazard, so the PPE matrix can match a rule
 * to a worker by Job + Hazard + Activity: the worker's assigned activity carries
 * the hazard, and an activity/hazard-scoped PPE rule now narrows to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_activities', function (Blueprint $table) {
            $table->string('hazard', 160)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_activities', function (Blueprint $table) {
            $table->dropColumn('hazard');
        });
    }
};
