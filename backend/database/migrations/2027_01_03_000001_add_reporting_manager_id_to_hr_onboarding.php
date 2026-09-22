<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the candidate → employee hire somewhere to record WHO the manager is.
 *
 * hr_onboarding already has a `reporting_manager_name` and a
 * `step_manager_assigned` flag, so the product has always had a "Reporting
 * Manager Assigned" step. It just had nowhere to put the answer: the flag is a
 * boolean and the name is a free string, so when OnboardingService creates the
 * employee it can pass a name and never an id. The hierarchy edge that the org
 * chart, the advance ladder and the phone app's approval queue all walk was
 * therefore never set by this path.
 *
 * WHY NOT REUSE hr_employee_onboardings.manager_id, which already exists:
 * that table sits DOWNSTREAM of the hire. Its own creator is called
 * createFromEmployee(), it requires an HrEmployee to exist, and it seeds itself
 * with `'manager_name' => $employee->reporting_manager_name` — it reads the
 * manager off the employee. Making it the source would invert the data flow and
 * ask a record created after the hire to decide something needed during it.
 * (Its manager_id is also dormant — fillable, with no writer anywhere.)
 *
 * Nullable on purpose and staying that way: a hire with no manager is legal
 * today — the first employee in a tenant genuinely has none — and nothing here
 * backfills or infers one. `reporting_manager_name` is kept beside it because it
 * is the only thing that can hold a manager who is not an employee record at all.
 *
 * No foreign key, matching hr_employees.reporting_manager_id, which has none
 * either. Adding one to a self-referencing employee table under SQLite means a
 * table rebuild, and the column is already guarded by a tenant-scoped
 * Rule::exists on every write path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->unsignedBigInteger('reporting_manager_id')
                ->nullable()
                ->after('reporting_manager_name')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->dropIndex(['reporting_manager_id']);
            $table->dropColumn('reporting_manager_id');
        });
    }
};
