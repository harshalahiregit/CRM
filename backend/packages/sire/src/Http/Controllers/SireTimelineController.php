<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\Report;
use Sire\Services\SireTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only. System events and user comments merged for display.
 *
 * There is deliberately NO update or delete route for a system event, here or
 * anywhere else in SIRE. Comments are edited through SireCommentController;
 * audit rows cannot be touched by any endpoint.
 */
class SireTimelineController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireTimelineService $timeline)
    {
    }

    public function __invoke(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->timeline->for($report, $this->sireUser()));
    }
}
