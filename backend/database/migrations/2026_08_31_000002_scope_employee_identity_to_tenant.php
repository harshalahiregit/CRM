<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make an employee's identity unique WITHIN a tenant, not across all of them.
 *
 * Two problems, both latent until a second tenant exists.
 *
 * `employee_code` carried a GLOBAL unique index while both generators counted
 * within a tenant — EmployeeService::create and the SangoeTrack importer alike.
 * So two tenants each produce SNE-2026-001, and the second insert throws inside
 * EmployeeService's transaction and surfaces as a generic 500. The importer is
 * worse: its de-duplication loop asks `where tenant_id = ? and employee_code = ?`
 * against a global index, so it exits the loop believing a code is free when
 * another tenant already holds it.
 *
 * `user_id` — the link between a login and a person, which the whole "everyone is
 * an employee" plan rests on — had a plain index and no uniqueness at all.
 * Nothing has ever written it (no creation path sets it, and StoreEmployeeRequest
 * has no rule for it), so there is no existing data to reconcile; but before
 * anything starts writing it, one login must not be able to claim two employee
 * rows in the same tenant.
 *
 * `sangoetrack_user_id` on this same table already uses (tenant_id, …), so this
 * brings the other two identity columns in line with a pattern the table already
 * follows.
 *
 * Existing rows are safe without de-duplication: a globally unique column is by
 * definition unique within each tenant, so the narrower index cannot fail on data
 * that satisfied the wider one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard rather than assume: if user_id was ever populated by hand, a
        // duplicate would make the unique index fail mid-migration and leave the
        // table half-altered. Better to stop with something readable.
        $dupes = DB::table('hr_employees')
            ->select('tenant_id', 'user_id', DB::raw('COUNT(*) as n'))
            ->whereNotNull('user_id')
            ->groupBy('tenant_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupes->isNotEmpty()) {
            $detail = $dupes->map(fn ($d) => "tenant {$d->tenant_id} / user {$d->user_id} ({$d->n} rows)")->implode('; ');

            throw new RuntimeException(
                'Cannot add the (tenant_id, user_id) unique index: one login is already linked to '
                . 'more than one employee. Resolve these first — ' . $detail
            );
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            // Named explicitly so the down() can drop them by name; Laravel's
            // generated names differ between drivers.
            $table->dropUnique('hr_employees_employee_code_unique');
            $table->unique(['tenant_id', 'employee_code'], 'hr_employees_tenant_code_unique');
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            // NULLs compare as distinct in a unique index on every driver we run,
            // so the many employees with no login remain valid.
            $table->unique(['tenant_id', 'user_id'], 'hr_employees_tenant_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropUnique('hr_employees_tenant_user_unique');
            $table->dropUnique('hr_employees_tenant_code_unique');
        });

        // Restoring the global index can fail where the per-tenant one succeeded —
        // that is the whole point of the change — so it is attempted rather than
        // assumed. A rollback that cannot restore it leaves the column indexed but
        // not globally unique, which is strictly safer than a half-applied schema.
        try {
            Schema::table('hr_employees', function (Blueprint $table) {
                $table->unique('employee_code', 'hr_employees_employee_code_unique');
            });
        } catch (\Throwable $e) {
            Schema::table('hr_employees', function (Blueprint $table) {
                $table->index('employee_code', 'hr_employees_employee_code_index');
            });
        }
    }
};
