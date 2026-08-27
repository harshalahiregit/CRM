<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Workforce + §23 Incidents — the doc's Project / Site / Department / Activity
 * context. TPV-local nullable strings (no cross-module FK), so risk and reporting
 * can group by these dimensions. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            foreach (['project', 'site', 'department'] as $col) {
                if (! Schema::hasColumn('tpv_workers', $col)) {
                    $table->string($col, 160)->nullable();
                }
            }
        });

        Schema::table('hsse_incidents', function (Blueprint $table) {
            foreach (['project', 'site', 'department', 'activity'] as $col) {
                if (! Schema::hasColumn('hsse_incidents', $col)) {
                    $table->string($col, 160)->nullable();
                }
            }
            if (! Schema::hasColumn('hsse_incidents', 'work_package_id')) {
                $table->unsignedBigInteger('work_package_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->dropColumn(['project', 'site', 'department']);
        });
        Schema::table('hsse_incidents', function (Blueprint $table) {
            $table->dropColumn(['project', 'site', 'department', 'activity', 'work_package_id']);
        });
    }
};
