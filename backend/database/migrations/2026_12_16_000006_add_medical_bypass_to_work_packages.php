<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not Applicable for the Project" — the admin bypass.
 *
 * A work package IS the project scope a worker is assigned to on both sides, so
 * the toggle lives there. When it is on, the medical check stops being a
 * prerequisite for that package's workers: safety induction is no longer blocked
 * and work authorization reports medical as Not Applicable rather than failing.
 *
 * A tenant-wide default lives in the medical settings group; this column is the
 * per-project override, and null means "follow the tenant default".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_work_packages', function (Blueprint $table) {
            $table->boolean('medical_not_applicable')->nullable()->after('name');
        });

        Schema::table('purchase_work_packages', function (Blueprint $table) {
            $table->boolean('medical_not_applicable')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_work_packages', function (Blueprint $table) {
            $table->dropColumn('medical_not_applicable');
        });

        Schema::table('purchase_work_packages', function (Blueprint $table) {
            $table->dropColumn('medical_not_applicable');
        });
    }
};
