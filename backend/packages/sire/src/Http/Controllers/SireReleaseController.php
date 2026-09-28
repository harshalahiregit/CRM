<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\StoreReleaseRequest;
use Sire\Models\Release;
use Sire\Services\SireReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SireReleaseController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireReleaseService $releases)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success(
            Release::query()
                ->forTenant($this->sireUser()->tenantId)
                ->with('owner:id,name')
                ->withCount(['shippedIssues', 'regressionsCaused'])
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->orderByDesc('release_date')
                ->orderByDesc('id')
                ->paginate((int) $request->integer('per_page', 25)),
        );
    }

    public function show(Request $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        return $this->success([
            'release'        => $release->load('owner:id,name'),
            'shipped_issues' => $this->releases->shippedIssues($release),
            'release_notes'  => $release->releaseNotes()->get(['id', 'audience', 'status', 'issue_count', 'published_at']),
        ]);
    }

    public function store(StoreReleaseRequest $request): JsonResponse
    {
        return $this->success(
            $this->releases->create((int) $this->sireUser()->tenantId, $request->validated(), $this->sireUser()),
            201,
        );
    }

    public function update(StoreReleaseRequest $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);
        $release->fill($request->validated())->save();

        return $this->success($release);
    }

    /*
     * markReleased() and rollBack() were removed in Phase 3.
     *
     * Shipping and rolling back are now governed transitions on
     * SireReleaseGovernanceController: they check gates, record approvals and
     * write to the override register. Leaving a second, ungated path to the same
     * status would have made the gates advisory by accident — which is the one
     * thing a gate engine must never be.
     */
}
