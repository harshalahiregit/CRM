<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — which driver regularly takes which vehicle.
 *
 * The allocation engine is asked to check "the assigned driver", and until now
 * nothing recorded one. This is the link.
 *
 * It lives on `driver_profiles`, not on `vehicles`, for two reasons: the row
 * already exists per person, and keeping every person-reference in one table
 * means the vehicle master stays free of people (the same separation that lets
 * the directory be swapped for the standalone one).
 *
 * This is the REGULAR assignment — who normally drives this truck. The driver
 * on a given trip is a dispatch decision and belongs to Developer 1; nothing
 * here claims to be that.
 *
 * "One regular driver per vehicle" is enforced in DriverService rather than by
 * a partial unique index, because a unique index over a nullable column is not
 * portable between MySQL and the SQLite used in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('assigned_vehicle_id')->nullable()->after('source_id')
                ->constrained('vehicles')->nullOnDelete();

            $table->index(['company_id', 'assigned_vehicle_id'], 'driver_profiles_company_vehicle_index');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropIndex('driver_profiles_company_vehicle_index');
            $table->dropConstrainedForeignId('assigned_vehicle_id');
        });
    }
};
