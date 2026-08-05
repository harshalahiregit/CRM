<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable statutory rules — PF, ESIC, PT, Bonus, Gratuity, TDS.
 *
 * Every rate, ceiling and slab lives in `config` (JSON) rather than in code, so a
 * budget change is a data edit and never a deploy. NOTHING is seeded: an absent or
 * inactive rule means "not configured", and the calculators return zero rather
 * than inventing a number. Statutory amounts must be entered by someone who can
 * verify them against the current law.
 *
 * `state` is only meaningful for Professional Tax, which is levied per state; the
 * resolver falls back to a state-less row when an employee's state has none.
 * `effective_from` lets a new financial year's slabs be entered ahead of time
 * without disturbing the run currently being processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_statutory_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // pf | esic | pt | bonus | gratuity | tds
            $table->string('rule_type', 20);
            // PT only: the state whose slabs these are. Null = tenant-wide default.
            $table->string('state', 80)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Rates/ceilings/slabs. Shape varies per rule_type — see the matching
            // calculator in App\Services\Hr\Statutory for the keys it reads.
            $table->json('config')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rule_type', 'is_active'], 'hr_stat_rules_lookup_idx');
            $table->index(['tenant_id', 'rule_type', 'state'], 'hr_stat_rules_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_statutory_rules');
    }
};
