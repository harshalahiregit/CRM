<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work state for an employee — the jurisdiction Professional Tax is levied under.
 *
 * PT was previously resolved from `location`, which holds a CITY ("Pune",
 * "MUMBAI"). That forced a PT rule per city and broke the moment a new office
 * opened in the same state. `location` is deliberately left untouched: it is used
 * elsewhere and existing rows keep their meaning.
 *
 * Nullable on purpose. Every existing employee gets NULL, and a NULL work state
 * means "PT not determinable" — payroll still runs and records the reason rather
 * than guessing a state and deducting the wrong tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_employees', 'work_state')) {
                $table->string('work_state', 80)->nullable();
            }
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->index(['tenant_id', 'work_state'], 'hr_emp_work_state_idx');
        });
    }

    /** Index-only: SQLite cannot drop a column an index still covers. */
    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropIndex('hr_emp_work_state_idx');
        });
    }
};
