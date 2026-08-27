<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §13 Worker → Activity link (activity_id on the worker).
 * §14 Explicit Trade, single training_status, and a named lifecycle_state
 *     (Nomination / Verification / Training / Gate_Pass / Exit) on the worker —
 *     the doc's named states, alongside the existing status/step machinery.
 * §11 Temporary-vendor capture fields: Purpose, Sponsor, Project, Scope,
 *     Workforce, Risk level, Required documents — TPV-local, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->nullable()->after('work_package_id');
            $table->string('trade', 120)->nullable()->after('skill_category');
            $table->string('training_status', 40)->nullable()->after('induction_status');
            $table->string('lifecycle_state', 40)->nullable()->after('status');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->string('temp_purpose', 255)->nullable();
            $table->string('temp_sponsor', 160)->nullable();
            $table->string('temp_project', 160)->nullable();
            $table->text('temp_scope')->nullable();
            $table->unsignedInteger('temp_workforce')->nullable();
            $table->string('temp_risk_level', 40)->nullable();
            $table->json('temp_required_documents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->dropColumn(['activity_id', 'trade', 'training_status', 'lifecycle_state']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'temp_purpose', 'temp_sponsor', 'temp_project', 'temp_scope',
                'temp_workforce', 'temp_risk_level', 'temp_required_documents',
            ]);
        });
    }
};
