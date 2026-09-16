<?php

namespace Sire\Http\Controllers;

use Sire\Contracts\SireAuthorizationProvider;
use Sire\Contracts\SireUserProvider;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\DashboardFilterRequest;
use Sire\Models\ReportCategory;
use Sire\Models\ReportSeverity;
use Sire\Services\SireDashboardService;
use Sire\Support\SirePriority;
use Sire\Support\SireWorkflow;
use Illuminate\Http\JsonResponse;

/**
 * SIRE — dashboard.
 *
 * Tiles and the register are separate endpoints so that changing a filter does not
 * refetch ten counts, and paging the table does not either.
 */
class SireDashboardController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;

    public function __construct(
        private readonly SireDashboardService $dashboard,
        private readonly SireAuthorizationProvider $authorization,
        private readonly SireUserProvider $users,
    ) {
    }

    /** GET /sire/dashboard — the ten tiles, respecting the current filters. */
    public function tiles(DashboardFilterRequest $request): JsonResponse
    {
        $user = $this->sireUser();

        return $this->success([
            'tiles'   => $this->dashboard->tiles((int) $user->tenantId, $user, $request->filters()),
            'filters' => $request->filters(),
        ]);
    }

    /** GET /sire/dashboard/register — the filtered, scoped list behind a tile. */
    public function register(DashboardFilterRequest $request): JsonResponse
    {
        $user = $this->sireUser();

        return $this->success($this->dashboard->register(
            (int) $user->tenantId,
            $user,
            $request->validated('scope'),
            $request->filters(),
            (int) ($request->validated('per_page') ?? 25),
        ));
    }

    /**
     * GET /sire/dashboard/options — everything the filter bar needs, in one call.
     * All tenant-scoped; the tenant list has exactly one entry today, which is why
     * the UI hides that control.
     */
    public function options(DashboardFilterRequest $request): JsonResponse
    {
        $user = $this->sireUser();
        $tenantId = (int) $user->tenantId;

        // The tenant NAME comes from the tenant provider, not from a relation on
        // the user. SireUserIdentity carries no tenant object, deliberately:
        // walking user->tenant->... is how a display label turns into an
        // unbounded read of whatever else the host hangs off a tenant.
        $tenant = app(\Sire\Contracts\SireTenantProvider::class)->currentTenant();

        return $this->success([
            'tenants' => [$tenant->toArray()],

            'modules' => \Sire\Models\Report::query()
                ->forTenant($tenantId)
                ->whereNotNull('module')
                ->distinct()
                ->orderBy('module')
                ->pluck('module'),

            'types' => ReportCategory::query()->forTenant($tenantId)
                ->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'code', 'name']),

            'severities' => ReportSeverity::query()->forTenant($tenantId)
                ->where('is_active', true)->orderBy('level')
                ->get(['id', 'code', 'name', 'level']),

            'priorities' => collect(SirePriority::ALL)
                ->map(fn (string $p) => ['value' => $p, 'label' => SirePriority::label($p)]),

            'statuses' => collect(SireWorkflow::STATES)
                ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label'], 'group' => $meta['group']])
                ->values(),

            'assignees' => $this->assignees($tenantId),
        ]);
    }

    /**
     * Who the assignment picker may offer.
     *
     * THE BUG THIS FIXES. This used to be the rosters and nothing else. Those are
     * settings keys (`sire.roles.leads` / `.developers` / `.qa`) with no screen
     * behind them, so when somebody joined the team nobody could assign them
     * anything until a developer edited a settings row by hand -- and the picker
     * simply looked broken. Worse, a workspace that never ran the seeder saw an
     * empty dropdown and no way to tell whether that was a permission problem, a
     * configuration problem or an outage.
     *
     * So the DIRECTORY is the source now: everyone the host says may reach SIRE
     * at all, read through config('sire.login_types') rather than guessed. The
     * rosters keep their real job -- saying who is a lead, a developer or QA --
     * and become a GROUPING on the result rather than a gate on it. A workspace
     * that maintains them gets its engineers listed first; one that never
     * touches them still gets a working picker.
     *
     * Roster members who are no longer in the directory are still listed. They
     * may be a leaver, or their role may have been renamed; dropping them
     * silently would make an existing assignee vanish from the field that shows
     * who the issue belongs to.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function assignees(int $tenantId)
    {
        $rosters = [];
        foreach (['leads', 'developers', 'qa'] as $roster) {
            foreach ($this->authorization->roster($tenantId, $roster) as $id) {
                $rosters[(int) $id][] = $roster;
            }
        }

        $directory = collect($this->users->directory($tenantId))
            ->keyBy(fn ($entry) => (int) $entry->id)
            ->map(fn ($entry) => $entry->toArray());

        // Anyone on a roster the directory could not return -- a leaver, or a
        // role the host has since renamed. Dropping them silently would make an
        // existing assignee vanish from the field that shows who owns the issue.
        $missing = array_values(array_diff(array_keys($rosters), $directory->keys()->all()));
        if ($missing !== []) {
            foreach ($this->users->lookupMany($tenantId, $missing) as $identity) {
                $directory[(int) $identity->id] = [
                    'id'           => (int) $identity->id,
                    'display_name' => $identity->displayName,
                    'kind'         => 'staff',
                    'role'         => $identity->role,
                    'department'   => null,
                    'designation'  => null,
                    'company'      => null,
                ];
            }
        }

        return $directory
            ->map(function (array $row) use ($rosters) {
                $on = $rosters[(int) $row['id']] ?? [];

                return $row + [
                    'rosters'     => $on,
                    'is_engineer' => $on !== [],
                ];
            })
            ->sortBy([
                // Staff before customers, engineering before the rest, then by
                // name. A picker is only useful if the person you meant is near
                // the top of it.
                fn ($a, $b) => (($a['kind'] === 'staff' ? 0 : 1) <=> ($b['kind'] === 'staff' ? 0 : 1)),
                fn ($a, $b) => ($b['is_engineer'] <=> $a['is_engineer']),
                fn ($a, $b) => strcasecmp((string) $a['display_name'], (string) $b['display_name']),
            ])
            ->values();
    }
}
