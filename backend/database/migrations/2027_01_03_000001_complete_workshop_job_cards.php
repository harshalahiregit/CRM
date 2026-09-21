<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-MAINT — finish the job card: T-29, T-30, T-31, T-32, T-33.
 *
 * The workshop screen has itemised parts and labour since it was built, and the
 * lines were summed into two totals and discarded. This adds the two tables that
 * keep them, plus the four columns a job card needs to be a real document: where
 * the work happened, what QC actually said, whether it was road tested, and how
 * long the vehicle was off the road.
 *
 * `qc_passed` is kept alongside the new `qc_result`. It is not redundant during
 * the transition: Dev 1's dispatch code and several tests read the boolean, and
 * removing it in the same migration that introduces the richer value would break
 * a consumer to save a column. The service writes both, and the boolean is
 * derived from the verdict so they cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            // T-29 — which yard or workshop did the work. A fleet uses several,
            // and "who fixed it last" is the first question when it comes back.
            $table->string('workshop_name', 100)->nullable()->after('vehicle_id');

            // T-31 — PASS / FAIL / CRITICAL_FAIL. Nullable because a card that
            // has not reached QC has no verdict, which is not the same as a fail.
            $table->string('qc_result', 20)->nullable()->after('qc_passed');
            $table->boolean('road_tested')->default(false)->after('qc_result');

            // T-33 — hours between opening and closing, stored at closure so the
            // figure does not drift as the clock moves.
            $table->decimal('downtime_hours', 10, 2)->nullable()->after('closed_at');

            // T-31 — which condemnation this card clears, if any.
            //
            // A critical failure must not be lifted by accident. Without this,
            // the only way to model "cleared" is "a later card passed QC", and
            // a routine oil change closed with a pass would un-condemn a
            // vehicle failed on its brakes. Clearing is now a deliberate act
            // naming the card it answers, and it leaves a record of who did it.
            $table->unsignedBigInteger('clears_job_id')->nullable()->after('qc_result')->index();
        });

        // T-30 — the parts that went on.
        Schema::create('maintenance_job_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('maintenance_job_id')->constrained('maintenance_jobs')->cascadeOnDelete();

            $table->string('part_name', 150);
            $table->string('part_number', 80)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('line_cost', 18, 2)->default(0);
            $table->string('supplier', 150)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'maintenance_job_id']);
        });

        // T-30 — the work that was done.
        Schema::create('maintenance_job_labour', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('maintenance_job_id')->constrained('maintenance_jobs')->cascadeOnDelete();

            $table->string('labour_type', 150);
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('hourly_rate', 18, 2)->default(0);
            $table->decimal('line_cost', 18, 2)->default(0);
            $table->string('technician', 120)->nullable();

            $table->timestamps();
            $table->index(['company_id', 'maintenance_job_id']);
        });

        // Existing closed cards have a verdict implied by the boolean. Backfill
        // it so the new column is not a hole in the history — but only where the
        // boolean was actually set, because null there means "never reached QC".
        if (Schema::hasColumn('maintenance_jobs', 'qc_result')) {
            \Illuminate\Support\Facades\DB::table('maintenance_jobs')
                ->whereNotNull('qc_passed')->where('qc_passed', true)
                ->update(['qc_result' => 'PASS']);

            // A historic false becomes FAIL, never CRITICAL_FAIL: the old column
            // could not express the difference, and inventing the more severe
            // reading would hold vehicles nobody ever condemned.
            \Illuminate\Support\Facades\DB::table('maintenance_jobs')
                ->whereNotNull('qc_passed')->where('qc_passed', false)
                ->update(['qc_result' => 'FAIL']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_job_labour');
        Schema::dropIfExists('maintenance_job_parts');

        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropColumn(['workshop_name', 'qc_result', 'clears_job_id', 'road_tested', 'downtime_hours']);
        });
    }
};
