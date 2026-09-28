<?php

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make every department and designation a record, and point the people at it.
 *
 * `hr_departments` and `hr_designations` have existed since the organization
 * tables shipped, an admin screen manages them, and `hr_employees` carries
 * `department_id` / `designation_id` to link to them. None of it was ever
 * connected: Staff Management builds its dropdown from a hardcoded list of eight
 * merged with `DISTINCT users.department`, and both the staff form and the
 * employee form write free text. So "Operations", "operations" and "Ops" are
 * three departments, nobody can rename one, and the org chart — which reads the
 * FK — sees almost nobody.
 *
 * This turns what people have already typed into records and links the rows to
 * them. It is additive: the free-text columns are left exactly as they are, so
 * every existing screen keeps reading what it read yesterday while the FK
 * becomes true underneath. Nothing to roll back and nothing to re-enter.
 *
 * MATCHING IS CASE- AND SPACE-INSENSITIVE. The whole reason this is needed is
 * that the same department was typed six ways; matching exactly would faithfully
 * reproduce all six as separate records. First spelling seen wins as the record's
 * name, and an admin can rename it afterwards — which is the point of it being a
 * record.
 *
 * Scoped per tenant throughout. `hr_departments` is unique on (tenant_id, name),
 * and a department belongs to one workspace; a global sweep would merge two
 * companies' org structures into one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_departments') || ! Schema::hasTable('hr_employees')) {
            return;
        }

        foreach ($this->tenantIds() as $tenantId) {
            $this->promote(
                $tenantId,
                HrDepartment::class,
                'department',
                'department_id',
                $this->typedValues($tenantId, 'department'),
            );

            if (Schema::hasTable('hr_designations')) {
                $this->promote(
                    $tenantId,
                    HrDesignation::class,
                    'designation',
                    'designation_id',
                    $this->typedValues($tenantId, 'designation'),
                );
            }
        }
    }

    /**
     * Deliberately does nothing.
     *
     * Deleting the records would break every employee whose department_id points
     * at one, including links an admin made by hand afterwards. The free-text
     * columns were never touched, so rolling this back restores nothing that was
     * taken away — there is nothing to restore.
     */
    public function down(): void {}

    /** Every tenant that has people, including the null tenant on old rows. */
    private function tenantIds(): array
    {
        $fromEmployees = DB::table('hr_employees')->distinct()->pluck('tenant_id');
        $fromUsers = Schema::hasTable('users')
            ? DB::table('users')->distinct()->pluck('tenant_id')
            : collect();

        return $fromEmployees->merge($fromUsers)
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What people have actually typed into this column, from both person tables.
     *
     * `users` is included because Staff Management writes departments there and
     * the two lists have drifted apart — a department that only ever existed on
     * a staff login is still a department this company uses.
     */
    private function typedValues(int $tenantId, string $column): array
    {
        $values = DB::table('hr_employees')
            ->where('tenant_id', $tenantId)
            ->whereNotNull($column)
            ->pluck($column);

        if (Schema::hasTable('users') && Schema::hasColumn('users', $column)) {
            $values = $values->merge(
                DB::table('users')
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull($column)
                    ->pluck($column)
            );
        }

        return $values->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->all();
    }

    /**
     * Create a record per distinct spelling, then link the employees to it.
     *
     * @param  class-string  $model
     * @param  array<string> $typed
     */
    private function promote(int $tenantId, string $model, string $nameColumn, string $fkColumn, array $typed): void
    {
        if (! Schema::hasColumn('hr_employees', $fkColumn)) {
            return;
        }

        // Existing records come first so an admin's own naming wins over whatever
        // somebody typed into a text box.
        $byKey = [];

        foreach ($model::where('tenant_id', $tenantId)->get() as $record) {
            $byKey[$this->key($record->name)] = $record;
        }

        foreach ($typed as $value) {
            $key = $this->key($value);

            if ($key === '' || isset($byKey[$key])) {
                continue;
            }

            $byKey[$key] = $model::create([
                'tenant_id' => $tenantId,
                'name'      => $value,
                'is_active' => true,
            ]);
        }

        if ($byKey === []) {
            return;
        }

        // Only rows that have no link yet. An employee already pointed at a
        // department was linked deliberately and must not be re-pointed by a
        // string match on a stale text column.
        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->whereNull($fkColumn)
            ->whereNotNull($nameColumn)
            ->get(['id', $nameColumn]);

        foreach ($employees as $employee) {
            $record = $byKey[$this->key((string) $employee->{$nameColumn})] ?? null;

            if ($record) {
                DB::table('hr_employees')->where('id', $employee->id)->update([$fkColumn => $record->id]);
            }
        }
    }

    /** Case- and whitespace-insensitive match key. "  Ops " and "ops" are one. */
    private function key(?string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';
    }
};
