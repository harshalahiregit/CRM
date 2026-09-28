<?php

namespace App\Support\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps an employee's department and designation NAMES pointed at their records.
 *
 * `hr_employees` carries both: the text somebody typed or picked, and the FK to
 * the record. Everything that displays a department reads the text; the org
 * chart, the reporting rollups and the skill comparison read the FK. Only the
 * text was ever written, so the FK was null on every row and half the product
 * quietly saw an empty organisation.
 *
 * Fixing the two forms would have left the importer, the onboarding conversion
 * and every future caller writing the text alone again. So this runs on save,
 * for every path, and there is nothing to remember.
 *
 * A name with no record CREATES one. The alternative is what we had — a
 * department that exists on people and nowhere an admin can see it. Creating it
 * puts it in Organization Setup where it can be renamed or merged, which is the
 * whole point of it being a record. Matching is case- and space-insensitive so
 * "ops", "Ops" and " OPS " are one department rather than three.
 */
final class OrgLink
{
    public static function apply(Model $employee): void
    {
        $tenantId = (int) ($employee->tenant_id ?? 0);

        if ($tenantId === 0) {
            return;
        }

        self::link($employee, $tenantId, 'department', 'department_id', HrDepartment::class);
        self::link($employee, $tenantId, 'designation', 'designation_id', HrDesignation::class);
    }

    /** @param class-string<Model> $model */
    private static function link(Model $employee, int $tenantId, string $nameColumn, string $fkColumn, string $model): void
    {
        $name = trim((string) ($employee->{$nameColumn} ?? ''));

        if ($name === '') {
            return;
        }

        // A link the caller set themselves wins over the text column. On a CREATE
        // every attribute counts as dirty, so testing the name alone would
        // overwrite an id that was passed in deliberately — which is how someone
        // assigning an employee to "Special Projects" would find them in
        // "Operations" because that is what the text field happened to say.
        if ($employee->{$fkColumn} && $employee->isDirty($fkColumn)) {
            return;
        }

        // Otherwise: resolve when there is no link, or when the name has moved.
        // Re-resolving an unchanged row would query twice per save for nothing.
        if ($employee->{$fkColumn} && ! $employee->isDirty($nameColumn)) {
            return;
        }

        $employee->{$fkColumn} = self::resolve($tenantId, $name, $model);
    }

    /** @param class-string<Model> $model */
    private static function resolve(int $tenantId, string $name, string $model): int
    {
        $key = self::key($name);

        // Compared in PHP rather than with a database LOWER() so the behaviour is
        // identical on MySQL and SQLite — collations differ, and a match that
        // works in tests and not in production is worse than no match.
        $existing = $model::where('tenant_id', $tenantId)
            ->get(['id', 'name'])
            ->first(fn ($record) => self::key((string) $record->name) === $key);

        // Deliberately not cached in a static. The table is tiny and an employee
        // save is not a hot path, whereas a static that survives a test's database
        // rebuild hands back ids for rows that no longer exist — a failure that
        // appears in an unrelated test and takes an afternoon to trace.
        return $existing
            ? (int) $existing->id
            : (int) $model::create(['tenant_id' => $tenantId, 'name' => $name, 'is_active' => true])->id;
    }

    private static function key(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
    }
}
