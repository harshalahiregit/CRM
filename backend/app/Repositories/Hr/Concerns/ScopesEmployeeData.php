<?php

namespace App\Repositories\Hr\Concerns;

use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;

/**
 * The one call an operational HR repository makes to the scope resolver.
 *
 * The report repositories each carry a private scoped() of their own, which was
 * fine at two or three of them. Fourteen copies of the same four lines is a
 * different thing: the next person to add a repository copies whichever one they
 * happened to open, and the day the supported-scope list changes there are
 * fourteen places to remember. This is that method, written once — it introduces
 * no new scope concept and resolves nothing itself.
 *
 * `$actor` is explicit rather than read from auth() here, because repositories in
 * this codebase are pure: none of them touch the container for request state, and
 * a console command or a queued job must keep working unscoped. A null actor
 * leaves the query exactly as it was, which is what makes adoption safe one
 * caller at a time.
 *
 * BRANCH is excluded from the supported set, as everywhere else: hr_employees
 * .branch is free text with no master behind it, so a role scoped to it would
 * silently read as global. Phase 1 made that call and this follows it rather
 * than quietly widening.
 */
trait ScopesEmployeeData
{
    /**
     * Restrict a query to the employees this actor may see.
     *
     * @param  string  $column  Where the employee id lives on THIS query —
     *                          'employee_id' usually, a qualified name when the
     *                          query joins, and 'id' on hr_employees itself.
     */
    protected function scopeToEmployees($query, ?User $actor, string $column = 'employee_id')
    {
        return app(ScopeResolver::class)->applyToQuery($query, $actor, $column, [
            DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM,
        ]);
    }

    /**
     * May this actor touch this specific employee's records at all?
     *
     * For the direct-id surfaces — "show me employee 41's payslips" — where a
     * list filter is not enough because the id arrives in the URL.
     */
    protected function assertEmployeeInScope(?User $actor, $employeeId): void
    {
        app(ScopeResolver::class)->assertCanActOnEmployee($actor, $employeeId);
    }
}
