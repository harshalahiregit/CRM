<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS M2 — what the fuel-variance and job-card workflows need on top of the
 * Sprint 1 baseline.
 *
 * Additive only. Every column is nullable or defaulted, so the existing rows
 * (and the seeded fleet) stay valid without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_transactions', function (Blueprint $table) {
            // Consumption is DERIVED at entry from the odometer gap since the
            // previous fill, and stored. Recomputing it on read would change
            // history every time an older fill is corrected.
            $table->decimal('km_driven', 12, 1)->nullable()->after('odometer');
            $table->decimal('efficiency_kmpl', 8, 2)->nullable()->after('km_driven');
            // Set when efficiency falls outside the benchmark tolerance — the
            // flag the exception report reads.
            $table->boolean('fuel_exception')->default(false)->after('efficiency_kmpl');
            $table->string('variance_note', 255)->nullable()->after('fuel_exception');

            // Emergency fills: why, and whether the customer carries it.
            // Nullable rather than defaulted — "nobody has decided yet" is a
            // real state and is not the same as "no".
            $table->string('emergency_reason', 255)->nullable()->after('is_emergency');
            $table->boolean('customer_recoverable')->nullable()->after('emergency_reason');

            // The physical receipt. Private disk; served through a controller,
            // never a public URL.
            $table->string('receipt_path', 255)->nullable()->after('customer_recoverable');
        });

        Schema::table('maintenance_jobs', function (Blueprint $table) {
            // A safety-critical job is the one that must BLOCK allocation.
            // Brakes are not the same as a broken cabin light, and eligibility
            // has to be able to tell them apart.
            $table->boolean('is_safety_critical')->default(false)->after('status');
            $table->dateTime('opened_at')->nullable()->after('is_safety_critical');
            $table->dateTime('closed_at')->nullable()->after('opened_at');
            // QC release: who signed the vehicle back onto the road, and when.
            $table->boolean('qc_passed')->nullable()->after('closed_at');
            $table->unsignedBigInteger('released_by')->nullable()->after('qc_passed');

            $table->index(['company_id', 'is_safety_critical']);
        });
    }

    public function down(): void
    {
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'km_driven', 'efficiency_kmpl', 'fuel_exception', 'variance_note',
                'emergency_reason', 'customer_recoverable', 'receipt_path',
            ]);
        });

        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_safety_critical']);
            $table->dropColumn(['is_safety_critical', 'opened_at', 'closed_at', 'qc_passed', 'released_by']);
        });
    }
};
