<?php

namespace App\Services\Auth;

use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Support\Hr\DataScope;
use Illuminate\Support\Facades\Log;

/**
 * Which records an already-authorised person may see.
 *
 * Permission has run by the time anything here is called. This answers only the
 * second question — global, own, department, branch or team — and it answers it
 * ONCE, as a list of employee ids, so a module does not restate the rule in its
 * own query builder. That restating is what went wrong the first time: scope
 * existed, was computed correctly, and was consulted in a single place that
 * rendered menus.
 *
 * The primitive is deliberately small:
 *
 *     visibleEmployeeIds($actor)  →  null  = no restriction (global)
 *                                    []    = nothing
 *                                    [ids] = exactly these
 *
 * Every adopted module maps that onto its own column — hr_employees.id for the
 * directory, hr_leave_applications.employee_id for leave — and a module added
 * later is one whereIn() away rather than a new copy of the hierarchy walk.
 *
 * THE FAILURE DIRECTION IS THE POINT. An actor whose scope needs an employee
 * record and has none resolves to [] and sees nothing, never to null and the
 * whole company. AdvanceTierService::scopeQueue() already made that choice
 * ("Nothing is the safe answer, not everything"); this generalises it rather
 * than replacing it — advances is untouched and keeps its own tier logic.
 */
class ScopeResolver
{
    /** Guards a reporting line that loops, so a bad edge cannot hang a request. */
    private const MAX_DEPTH = 20;

    /**
     * The width this actor's role gives them.
     *
     * An admin is global regardless: they bypass the permission grid, and a
     * scope that could lock the administrator out of the screen that fixes
     * scopes is a trap. A STAFF member with no role is global too — that is
     * everybody who existed before roles were records, and their access must not
     * change.
     *
     * A PORTAL identity is not. That back-compat rule was written for colleagues
     * who predate the roles table, and it silently extended to clients, vendors,
     * third-party vendors and company logins, because they have no staff role
     * either and normalise(null) answers GLOBAL.
     *
     * Confirmed on live data: two `client` accounts resolved to GLOBAL, and one
     * of them read the workforce's leave register — employee names, codes,
     * departments, dates and the reason given for each absence — through
     * /hr/leave/applications.
     *
     * EnsureStaffPermission already draws this line for the permission grid:
     * "a portal account must never satisfy a staff gate". The same line belongs
     * here, because a data scope is the other half of the same question. OWN
     * rather than a refusal, so the resolver keeps returning a scope and its
     * callers keep working: a portal identity with no employee record resolves
     * to an empty id set, which employeeIds() already treats as "no honest set
     * of records for them".
     */
    public function scopeFor(User $actor): string
    {
        if ($actor->isAdmin()) {
            return DataScope::GLOBAL;
        }

        if (! $actor->isStaffAccount()) {
            return DataScope::OWN;
        }

        $role = $actor->relationLoaded('staffRole') ? $actor->staffRole : $actor->staffRole()->first();

        return DataScope::normalise($role?->scope);
    }

    /**
     * The employee ids this actor may see, or null for "no restriction".
     *
     * @param  array<string>  $supported  Scopes the CALLING module can honour.
     *                                    One it cannot is treated as global and
     *                                    logged — see supportedOrGlobal().
     */
    public function visibleEmployeeIds(User $actor, array $supported = DataScope::ALL): ?array
    {
        $scope = $this->supportedOrGlobal($actor, $this->scopeFor($actor), $supported);

        if ($scope === DataScope::GLOBAL) {
            return null;
        }

        $me = $this->employeeFor($actor);

        if (! $me) {
            // Authorised, but with no place in the organisation chart. There is
            // no honest set of records for them, and "everything" is the wrong
            // guess.
            return [];
        }

        return match ($scope) {
            DataScope::OWN        => [(int) $me->id],
            DataScope::DEPARTMENT => $this->departmentIds($me),
            DataScope::BRANCH     => $this->branchIds($me),
            DataScope::TEAM       => $this->teamIds($me),
            default               => null,
        };
    }

    /**
     * Apply the restriction to a query on a column holding employee ids.
     *
     * The whole adoption surface for a module is this one call. `null` leaves
     * the query exactly as it was, which is what keeps global behaviour
     * byte-identical to before.
     */
    public function applyToQuery($query, ?User $actor, string $column = 'employee_id', array $supported = DataScope::ALL)
    {
        if (! $actor) {
            // No actor supplied — a console command, a job, an older caller that
            // has not been threaded through yet. Unscoped, exactly as before.
            return $query;
        }

        $ids = $this->visibleEmployeeIds($actor, $supported);

        if ($ids === null) {
            return $query;
        }

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $ids);
    }

    /**
     * May this actor act on this employee's records at all?
     *
     * The companion to applyToQuery(). A list is only half of a data boundary:
     * narrowing the index while leaving show/update/approve open means the
     * boundary holds until somebody types an id into the URL, which is the
     * first thing anyone tries.
     *
     * Used from CONTROLLERS rather than from inside the shared services,
     * deliberately. LeaveApprovalService::approve() and AttendanceService are
     * called by the attendance app as well as by the CRM, and putting the check
     * inside them would change what the app does — the app already scopes its
     * own decisions by reporting line in HrmAdminController::denyDecisionFor().
     * Guarding at the CRM's own controllers leaves the app's path untouched.
     *
     * @param  int|null  $employeeId  Whose record is being acted on.
     */
    public function canActOnEmployee(?User $actor, $employeeId): bool
    {
        if (! $actor || $employeeId === null) {
            return true;   // nothing to check against — unscoped, as before
        }

        $ids = $this->visibleEmployeeIds($actor);

        return $ids === null || in_array((int) $employeeId, $ids, true);
    }

    /**
     * 404, not 403.
     *
     * Telling somebody "you may not see employee 41" confirms that employee 41
     * exists and that they are outside this person's department — which is the
     * thing the boundary is there to withhold. A record beyond your scope should
     * look the same as a record that is not there, which is also how the tenant
     * guard beside it already behaves (assertTenant aborts 404).
     */
    public function assertCanActOnEmployee(?User $actor, $employeeId): void
    {
        abort_unless($this->canActOnEmployee($actor, $employeeId), 404, 'Employee not found');
    }

    /* ── actor context ────────────────────────────────────────────────── */

    /** The actor's own employee record, tenant-scoped. */
    public function employeeFor(User $actor): ?HrEmployee
    {
        return HrEmployee::where('tenant_id', $actor->tenant_id)
            ->where('user_id', $actor->id)
            ->first();
    }

    /* ── the four narrowing scopes ────────────────────────────────────── */

    /**
     * Matched on department_id, not the free-text `department`.
     *
     * Both columns exist and both are populated, but the string is what people
     * type and the id is what Org Setup owns. An employee whose department is
     * unset gets [own] rather than everyone else who is also unset — "null" is
     * not a department two people can share.
     */
    private function departmentIds(HrEmployee $me): array
    {
        if (! $me->department_id) {
            return [(int) $me->id];
        }

        return HrEmployee::where('tenant_id', $me->tenant_id)
            ->where('department_id', $me->department_id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Branch is a free-text column with no master behind it and, on this
     * database, no values at all. Same rule as department: an actor with no
     * branch sees themselves, not everybody else who also has none.
     *
     * Modules do not currently declare branch as supported (see the adoption
     * sites) precisely because of that — this is here so the scope is real the
     * day branch data is, not so it can be switched on over empty columns.
     */
    private function branchIds(HrEmployee $me): array
    {
        if (! $me->branch) {
            return [(int) $me->id];
        }

        return HrEmployee::where('tenant_id', $me->tenant_id)
            ->where('branch', $me->branch)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The actor plus everyone reporting through them, to any depth.
     *
     * Walked level by level rather than recursively per row, so a 200-person
     * tree is a handful of queries instead of 200. MAX_DEPTH stops a reporting
     * line that loops — EmployeeService rejects cycles on the way in, but data
     * predating that check, or written by the movement flow, could still hold one.
     */
    private function teamIds(HrEmployee $me): array
    {
        $all     = [(int) $me->id];
        $frontier = [(int) $me->id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $next = HrEmployee::where('tenant_id', $me->tenant_id)
                ->whereIn('reporting_manager_id', $frontier)
                ->whereNotIn('id', $all)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($next === []) {
                break;
            }

            $all      = array_merge($all, $next);
            $frontier = $next;
        }

        return $all;
    }

    /* ── module capability ────────────────────────────────────────────── */

    /**
     * A scope the calling module cannot honour falls back to global.
     *
     * Only one thing can happen when a module has no column to answer a scope
     * with, and neither option is good: return everything (too wide) or nothing
     * (locks people out of their job). Widening is the approved choice for this
     * phase, and it is logged rather than silent so the gap shows up in a log
     * search instead of in a conversation about missing records.
     */
    private function supportedOrGlobal(User $actor, string $scope, array $supported): string
    {
        if ($scope === DataScope::GLOBAL || in_array($scope, $supported, true)) {
            return $scope;
        }

        Log::channel('hr')->info('Scope not supported by this module — falling back to global', [
            'user_id'   => $actor->id,
            'tenant_id' => $actor->tenant_id,
            'requested' => $scope,
            'supported' => $supported,
        ]);

        return DataScope::GLOBAL;
    }
}
