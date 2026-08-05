<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comments #41, #42 and #43.
 *
 * #43 — SKILLS on the four org masters, plus on the employee.
 *   The comment asks the system to "indicate relevant skills and score of the
 *   individual" when an employee is assigned to a department/designation/grade/
 *   role. That needs both sides: what the position expects, and what the person
 *   has. Employees carried no skills at all, so the comparison had nothing to
 *   compare against. Stored as JSON arrays, matching how `hr_candidates.skills`
 *   and `hr_manpower_requests.required_skills` already work — same shape, so the
 *   matcher reads all of them the same way.
 *
 * #41 + #42 — ONE movements table, not two.
 *   A department transfer and a promotion/demotion are the same event with a
 *   different label: an employee moves from one position to another on a date,
 *   for a reason. Two tables would duplicate every column and drift. The
 *   `movement_type` discriminates; the from/to columns are shared.
 *
 *   Both the master ID and the free-text name are recorded on each side. The
 *   employee record itself stores `department`/`designation` as TEXT (with
 *   optional `*_id` columns), so a movement that captured only IDs could not
 *   reproduce what the record actually said at the time.
 */
return new class extends Migration
{
    /** The org masters that gain a skills profile. */
    private array $masters = ['hr_departments', 'hr_designations', 'hr_grades', 'hr_job_roles'];

    public function up(): void
    {
        foreach ($this->masters as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'skills')) {
                    $t->json('skills')->nullable();
                }
            });
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_employees', 'skills')) {
                $table->json('skills')->nullable();
            }
        });

        Schema::create('hr_employee_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            // Transfer | Promotion | Demotion | Redesignation
            $table->string('movement_type', 20);
            $table->date('effective_date');

            // Both sides captured as (id, name) — see the class note above.
            $table->unsignedBigInteger('from_department_id')->nullable();
            $table->string('from_department', 150)->nullable();
            $table->unsignedBigInteger('to_department_id')->nullable();
            $table->string('to_department', 150)->nullable();

            $table->unsignedBigInteger('from_designation_id')->nullable();
            $table->string('from_designation', 150)->nullable();
            $table->unsignedBigInteger('to_designation_id')->nullable();
            $table->string('to_designation', 150)->nullable();

            $table->unsignedBigInteger('from_grade_id')->nullable();
            $table->unsignedBigInteger('to_grade_id')->nullable();

            $table->unsignedBigInteger('from_manager_id')->nullable();
            $table->unsignedBigInteger('to_manager_id')->nullable();

            // The recommendation this movement acted on, when it came from one.
            // Nullable: a transfer is usually a direct HR action with no review
            // behind it, and forcing one would block the common case.
            $table->unsignedBigInteger('promotion_recommendation_id')->nullable();

            $table->text('reason')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('actioned_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_date'], 'hr_emp_move_idx');
            $table->index(['tenant_id', 'movement_type'], 'hr_emp_move_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_movements');
    }
};
