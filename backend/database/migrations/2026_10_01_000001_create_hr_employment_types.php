<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of employment somebody is on: Permanent, Contract, Intern, whatever
 * the company calls it.
 *
 * NOT A RENAME OF ANYTHING. Three nearby things exist and none of them is this:
 *
 *   hr_hiring_requests.employment_type is a REQUISITION attribute
 *   (Full-time / Part-time / Contract / Internship) that
 *   RestJobBoardChannel publishes straight to external job boards as the
 *   canonical `employment_type` field. Those boards expect a fixed
 *   vocabulary, so it stays a system enum and is deliberately untouched.
 *
 *   hr_employees.worker_type is a three-value org-chart concept
 *   (employee / consultant / freelancer) that OrgChartService groups, counts
 *   and filters on, with a NOT NULL default. It overlaps in spirit and is not
 *   the same question; merging them would rewrite the org chart.
 *
 *   hr_employees.category is the skill category the salary register uses to
 *   decide minimum-wage legality.
 *
 * So the employee-level employment classification genuinely had nowhere to
 * live, and a company wanting "Probation" or "Retainer" had to ask a
 * developer. This is the master for it, shaped exactly like hr_grades —
 * tenant-owned, unique name per tenant, active flag, sort order — so it joins
 * the Organization Setup screen rather than inventing a pattern.
 *
 * NO SEEDED VALUES. Not even the obvious ones: the whole point is that the
 * company defines them, and shipping "Permanent, Contract, Intern" would make
 * a guess look like a decision. A workspace with none configured behaves
 * exactly as it does today, because employment_type_id is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employment_types')) {
            Schema::create('hr_employment_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 120);
                $table->string('code', 40)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                // Same guarantee every other org master has: two companies may
                // both call something "Contract"; one company may not.
                $table->unique(['tenant_id', 'name'], 'hr_emp_type_tenant_name_uniq');
            });
        }

        if (Schema::hasTable('hr_employees') && ! Schema::hasColumn('hr_employees', 'employment_type_id')) {
            Schema::table('hr_employees', function (Blueprint $table) {
                // Nullable and not backfilled. Nothing on this table holds an
                // employment type today, so there is no legacy string to map
                // from and nothing to guess at — every existing employee stays
                // exactly as it is, with no type until somebody chooses one.
                //
                // No database foreign key, matching grade_id and job_role_id
                // beside it: tenancy is enforced in the service, and a hard FK
                // would make deleting a master fail with a driver error rather
                // than the product's own "N employee(s) are assigned to it".
                $table->unsignedBigInteger('employment_type_id')->nullable()->after('grade_id');
                $table->index(['tenant_id', 'employment_type_id'], 'hr_emp_type_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_employees') && Schema::hasColumn('hr_employees', 'employment_type_id')) {
            Schema::table('hr_employees', function (Blueprint $table) {
                $table->dropIndex('hr_emp_type_idx');
                $table->dropColumn('employment_type_id');
            });
        }

        Schema::dropIfExists('hr_employment_types');
    }
};
