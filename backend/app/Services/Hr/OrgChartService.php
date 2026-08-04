<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;

/**
 * Review comment #29 — "Organization chart – auto create and update based on
 * employee, consultant, freelancer added in system".
 *
 * AUTO means derived, never authored: the tree is computed from
 * `hr_employees.reporting_manager_id` on every read. There is no org-chart table
 * to fall out of date, so adding a hire or changing a manager updates the chart
 * with no second write and no sync job. That is also why this service only reads.
 *
 * Two structural hazards are handled explicitly rather than left to blow up:
 *
 *  - CYCLES. Nothing in the schema stops A reporting to B and B to A; a naive
 *    recursive build would loop until it exhausted memory. Cycle members are
 *    detected and surfaced as `issues`, because a silent fix hides a data error
 *    that only a human can resolve.
 *  - ORPHANS. An employee whose manager is inactive, deleted or in another tenant
 *    would simply vanish from a tree built by descent. They are re-attached at
 *    the root and reported, so the chart's headcount always matches the filter.
 */
class OrgChartService
{
    /**
     * @param  array  $filters  ['department' => ?string, 'worker_type' => ?string,
     *                           'include_inactive' => ?bool]
     */
    public function tree(int $tenantId, array $filters = []): array
    {
        $people = $this->people($tenantId, $filters);

        if ($people->isEmpty()) {
            return [
                'roots' => [], 'total' => 0, 'max_depth' => 0,
                'issues' => [], 'legend' => $this->legend($people),
            ];
        }

        $byId     = $people->keyBy('id');
        $children = $people->groupBy(fn ($e) => $e->reporting_manager_id ?: 0);

        $issues = [];

        // A manager who is not in the result set (inactive, deleted, filtered out,
        // or another tenant's) is not a parent we can descend from.
        $unreachable = $people->filter(
            fn ($e) => $e->reporting_manager_id && ! $byId->has($e->reporting_manager_id)
        );

        $cycleMembers = $this->cycleMembers($people, $byId);

        foreach ($unreachable as $e) {
            $issues[] = [
                'type' => 'missing_manager', 'employee_id' => $e->id, 'employee_name' => $e->name,
                'detail' => 'Reporting manager is not in this view, so they are shown at the top level.',
            ];
        }
        foreach ($cycleMembers as $id) {
            $issues[] = [
                'type' => 'reporting_cycle', 'employee_id' => $id,
                'employee_name' => $byId[$id]->name ?? null,
                'detail' => 'This reporting line loops back on itself and must be corrected.',
            ];
        }

        // Roots: no manager, a manager we cannot reach, or a cycle member — the
        // last so a loop still renders instead of disappearing from the chart.
        $roots = $people->filter(fn ($e) => ! $e->reporting_manager_id
            || ! $byId->has($e->reporting_manager_id)
            || in_array($e->id, $cycleMembers, true));

        $nodes = $roots->map(fn ($e) => $this->node($e, $children, $cycleMembers, 1))
            ->values()->all();

        return [
            'roots'     => $nodes,
            'total'     => $people->count(),
            // Measured from the built tree rather than threaded through the
            // recursion as a by-reference counter: the recursive step is an arrow
            // function, and those capture by VALUE, so a `&$depth` passed into one
            // silently increments a copy.
            'max_depth' => $this->depthOf($nodes),
            'issues'    => $issues,
            'legend'    => $this->legend($people),
        ];
    }

    /**
     * One node and everything under it.
     *
     * `$visited` guards the descent itself: even with cycle roots hoisted above, a
     * loop reached from outside it would still recurse forever.
     */
    private function node($employee, $children, array $cycleMembers, int $level, array $visited = []): array
    {
        $visited[$employee->id] = true;

        $reports = collect($children[$employee->id] ?? [])
            ->reject(fn ($c) => isset($visited[$c->id]) || $c->id === $employee->id)
            // A cycle member is rendered at the root, so it must not also appear
            // here — it would show the same person twice in one chart.
            ->reject(fn ($c) => in_array($c->id, $cycleMembers, true))
            ->map(fn ($c) => $this->node($c, $children, $cycleMembers, $level + 1, $visited))
            ->values()->all();

        return [
            'id'            => $employee->id,
            'name'          => $employee->name,
            'employee_code' => $employee->employee_code,
            'designation'   => $employee->designation,
            'department'    => $employee->department,
            'worker_type'   => $employee->worker_type ?: 'employee',
            'status'        => $employee->status,
            'level'         => $level,
            // The whole sub-tree, not just direct reports — "how many people sit
            // under this person" is the question an org chart is asked.
            'reports_count' => $this->countDescendants($reports),
            'direct_count'  => count($reports),
            'children'      => $reports,
        ];
    }

    private function countDescendants(array $nodes): int
    {
        return array_reduce($nodes, fn ($carry, $n) => $carry + 1 + $n['reports_count'], 0);
    }

    /** How many levels deep the deepest branch runs. */
    private function depthOf(array $nodes): int
    {
        return array_reduce($nodes, fn ($max, $n) => max($max, $n['level'], $this->depthOf($n['children'])), 0);
    }

    /**
     * Everyone caught in a reporting loop.
     *
     * Walks the manager chain from each person; landing on someone already seen
     * on THIS walk means a loop. Resolved ids are memoised so the whole set costs
     * one pass rather than one walk per person.
     */
    private function cycleMembers($people, $byId): array
    {
        $inCycle = [];
        $settled = [];

        foreach ($people as $person) {
            $path    = [];
            $current = $person;

            while ($current && ! isset($settled[$current->id])) {
                if (isset($path[$current->id])) {
                    // Everything from here on this path is part of the loop.
                    foreach (array_keys($path) as $id) {
                        $inCycle[$id] = true;
                    }
                    break;
                }
                $path[$current->id] = true;
                $current = $current->reporting_manager_id ? ($byId[$current->reporting_manager_id] ?? null) : null;
            }

            foreach (array_keys($path) as $id) {
                $settled[$id] = true;
            }
        }

        return array_keys($inCycle);
    }

    /** Headcount by worker type — what the chart is actually showing. */
    private function legend($people): array
    {
        return collect(HrEmployee::WORKER_TYPES)
            ->mapWithKeys(fn ($t) => [$t => $people->filter(fn ($e) => ($e->worker_type ?: 'employee') === $t)->count()])
            ->all();
    }

    private function people(int $tenantId, array $filters)
    {
        $query = HrEmployee::where('tenant_id', $tenantId)
            ->where('include_in_org_chart', true);

        // Active only by default: an org chart is a picture of who works here now,
        // and leavers would leave dangling branches.
        if (empty($filters['include_inactive'])) {
            $query->where('status', '!=', 'Inactive');
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['worker_type']) && $filters['worker_type'] !== 'All') {
            $query->where('worker_type', $filters['worker_type']);
        }

        return $query->orderBy('name')->get([
            'id', 'name', 'employee_code', 'designation', 'department',
            'reporting_manager_id', 'worker_type', 'status',
        ]);
    }
}
