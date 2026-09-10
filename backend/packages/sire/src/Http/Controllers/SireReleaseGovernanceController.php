<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\ReleaseOverrideRequest;
use Sire\Http\Requests\ReleaseTransitionRequest;
use Sire\Models\Release;
use Sire\Models\ReleaseOverride;
use Sire\Services\SireReleaseDashboardService;
use Sire\Services\SireReleaseGateService;
use Sire\Services\SireReleaseGovernanceService;
use Sire\Support\SireReleaseStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SIRE — release governance.
 *
 * This is a governance record, not a deployment tool. There is no pipeline in
 * this CRM to integrate with, SIRE triggers nothing and holds no credential that
 * could. `deployment_ref` is a field to paste a build id into on the day one
 * exists.
 */
class SireReleaseGovernanceController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireReleaseGovernanceService $governance,
        private readonly SireReleaseDashboardService $dashboard,
        private readonly SireReleaseGateService $gates,
    ) {
    }

    /** GET /sire/release-board — one row per release, every governance column. */
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'       => ['nullable', 'string', 'max:128'],
            'release_type' => ['nullable', 'string', 'max:24'],
            'per_page'     => ['nullable', 'integer', 'between:1,100'],
        ]);

        return $this->success($this->dashboard->rows(
            (int) $this->sireUser()->tenantId,
            $this->sireUser(),
            $data,
            (int) ($data['per_page'] ?? 25),
        ));
    }

    /** GET /sire/releases/{release}/governance — gates recomputed on open. */
    public function show(Request $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        return $this->success($this->dashboard->detail($release, $this->sireUser()));
    }

    /** POST /sire/releases/{release}/transitions */
    public function transition(ReleaseTransitionRequest $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        return $this->success($this->governance->apply(
            $release,
            $request->validated('action'),
            $this->sireUser(),
            $request->safe()->except('action'),
        ));
    }

    /** POST /sire/releases/{release}/gates/evaluate — force a recompute. */
    public function evaluate(Request $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        return $this->success($this->gates->evaluate($release));
    }

    /** POST /sire/releases/{release}/override — authorised emergency override. */
    public function override(ReleaseOverrideRequest $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        return $this->success($this->governance->override($release, $request->validated(), $this->sireUser()), 201);
    }

    public function revokeOverride(Request $request, ReleaseOverride $override): JsonResponse
    {
        $this->assertTenantOwnership($override);

        $reason = (string) $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        return $this->success($this->governance->revokeOverride($override, $this->sireUser(), $reason));
    }

    /**
     * GET /sire/release-overrides — the override register.
     *
     * A queryable register, not only an audit line: "how many overrides last
     * quarter, on which gates, authorised by whom" is the question governance
     * actually gets asked.
     */
    public function overrides(Request $request): JsonResponse
    {
        return $this->success(
            ReleaseOverride::query()
                ->forTenant($this->sireUser()->tenantId)
                ->with(['authorizer:id,name', 'release:id,version,name,status'])
                ->when($request->query('reason'), fn ($q, $v) => $q->where('reason', $v))
                ->when($request->boolean('active_only'), fn ($q) => $q->active())
                ->latest('authorized_at')
                ->paginate((int) $request->integer('per_page', 25)),
        );
    }

    /** GET /sire/release-gates — the configured gates and what they mean. */
    public function gateConfig(Request $request): JsonResponse
    {
        $tenantId = (int) $this->sireUser()->tenantId;

        return $this->success([
            'gates'    => $this->gates->gatesFor($tenantId),
            'defaults' => SireReleaseGateService::DEFAULT_GATES,
            'labels'   => SireReleaseGateService::LABELS,
            'statuses' => SireReleaseStatus::LABELS,
        ]);
    }
}
