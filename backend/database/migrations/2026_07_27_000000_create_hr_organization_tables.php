<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Setup masters — the foundation the rest of HR Operations
 * (letters, PMS, probation, reports) references. Today department / designation
 * live as free-text strings on hr_employees; this migration introduces real
 * masters and adds NULLABLE reference columns to hr_employees.
 *
 * Backward-compatible by design: the existing string columns are left intact
 * (existing reads keep working), the new *_id columns are additive and nullable,
 * and existing distinct department/designation values are backfilled into the
 * masters + linked, per tenant, so the module is populated on first load.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_grades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('level')->nullable();          // seniority order (1 = junior … n = senior)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('head_employee_id')->nullable();  // Department Head (an employee)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_designations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_job_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Additive, nullable reference columns on hr_employees. The legacy string
        // columns (department, designation, reporting_manager_name) are untouched.
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('department');
            $table->unsignedBigInteger('designation_id')->nullable()->after('designation');
            $table->unsignedBigInteger('grade_id')->nullable()->after('designation_id');
            $table->unsignedBigInteger('job_role_id')->nullable()->after('grade_id');
            $table->unsignedBigInteger('reporting_manager_id')->nullable()->after('reporting_manager_name');
        });

        $this->backfill();
    }

    /**
     * Seed the department & designation masters from the distinct values already
     * present on hr_employees, per tenant, then link each employee to the master
     * it matches. Best-effort — wrapped so a failure never blocks the migration.
     */
    private function backfill(): void
    {
        try {
            $now = now();

            foreach (['department' => 'hr_departments', 'designation' => 'hr_designations'] as $column => $masterTable) {
                $groups = DB::table('hr_employees')
                    ->select('tenant_id', $column)
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->distinct()
                    ->get();

                foreach ($groups as $g) {
                    $name = trim($g->{$column});
                    if ($name === '') {
                        continue;
                    }

                    // Respect the (tenant_id, name) unique — insert only if absent.
                    $existing = DB::table($masterTable)
                        ->where('tenant_id', $g->tenant_id)
                        ->where('name', $name)
                        ->value('id');

                    $id = $existing ?: DB::table($masterTable)->insertGetId([
                        'tenant_id'  => $g->tenant_id,
                        'name'       => $name,
                        'is_active'  => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('hr_employees')
                        ->where('tenant_id', $g->tenant_id)
                        ->where($column, $g->{$column})
                        ->update([$column.'_id' => $id]);
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: masters can also be created by hand in the UI.
        }
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['department_id', 'designation_id', 'grade_id', 'job_role_id', 'reporting_manager_id']);
        });
        Schema::dropIfExists('hr_job_roles');
        Schema::dropIfExists('hr_designations');
        Schema::dropIfExists('hr_departments');
        Schema::dropIfExists('hr_grades');
    }
};
