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

            // Assignable people come from the rosters, resolved through the
            // SDK. Querying the users table for role IN ('admin','staff') was a
            // hardcoded guess at one host's role names, and produced an empty
            // picker in any application that calls them anything else.
            'assignees' => collect(['leads', 'developers', 'qa'])
                ->flatMap(fn (string $roster) => $this->authorization->roster($tenantId, $roster))
                ->unique()
                ->pipe(fn ($ids) => collect($this->users->lookupMany($tenantId, $ids->all())))
                ->map(fn ($identity) => $identity->toArray())
                ->sortBy('display_name')
                ->values(),
        ]);
    }
}
