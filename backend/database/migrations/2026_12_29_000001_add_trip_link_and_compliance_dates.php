<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS — what the two service contracts and the pre-dispatch gate need.
 *
 * 1. `maintenance_jobs.trip_id` — getTripOperatingCosts() sums "maintenance
 *    allocations attached to trip_id", and a job card had no way to be attached
 *    to one. A breakdown repair during a trip is attributable to that trip;
 *    routine servicing is not and simply leaves this null. No foreign key:
 *    Dispatch owns trips (golden rule 3 / STOS-TM-001).
 *
 * 2. The five statutory expiry dates on `vehicles` — Stage 4's compliance gate
 *    blocks dispatch on an EXPIRED DOCUMENT, and a single rolled-up
 *    `compliance_status` flag cannot say which document, whose desk, or when.
 *    Each has its own issuer and its own date, so each gets its own column.
 *
 * Driver licence expiry is deliberately absent: it belongs to a driver master
 * that does not exist yet in any domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('trip_id')->nullable()->after('vehicle_id')->index();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('registration_expiry')->nullable()->after('compliance_status');
            $table->date('insurance_expiry')->nullable()->after('registration_expiry');
            $table->date('fitness_expiry')->nullable()->after('insurance_expiry');
            $table->date('permit_expiry')->nullable()->after('fitness_expiry');
            $table->date('puc_expiry')->nullable()->after('permit_expiry');

            // A manual hold, set by the compliance desk for a reason no date
            // knows about (an accident, an ongoing dispute). Kept separate so
            // the nightly recompute can never clear a human decision.
            $table->boolean('compliance_hold')->default(false)->after('puc_expiry');
            $table->string('compliance_hold_reason', 255)->nullable()->after('compliance_hold');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropIndex(['trip_id']);
            $table->dropColumn('trip_id');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'registration_expiry', 'insurance_expiry', 'fitness_expiry',
                'permit_expiry', 'puc_expiry', 'compliance_hold', 'compliance_hold_reason',
            ]);
        });
    }
};
