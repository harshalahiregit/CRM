<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\ApplyTransitionRequest;
use Sire\Http\Requests\RecordActionRequest;
use Sire\Models\Report;
use Sire\Services\SireReportService;
use Sire\Services\SireWorkflowService;
use Illuminate\Http\JsonResponse;

/**
 * SIRE — every workflow move goes through here, and here does almost nothing.
 *
 * One endpoint per concept rather than one per transition: the state machine
 * already knows what is legal from where, so twenty near-identical controller
 * methods would be twenty places for the rules to drift.
 */
class ReportWorkflowController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireWorkflowService $workflow,
        private readonly SireReportService $reports,
    ) {
    }

    /** POST /sire/reports/{report}/transitions */
    public function transition(ApplyTransitionRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report); // 404, not 403

        $updated = $this->workflow->apply(
            $report,
            $request->validated('action'),
            $this->sireUser(),
            $request->safe()->except('action'),
        );

        return $this->success($this->reports->detail($updated));
    }

    /** POST /sire/reports/{report}/actions — work recorded without a status move. */
    public function action(RecordActionRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $updated = $this->workflow->recordAction(
            $report,
            $request->validated('action'),
            $this->sireUser(),
            $request->safe()->except('action'),
        );

        return $this->success($this->reports->detail($updated));
    }
}
