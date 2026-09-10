<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\StoreRecurrenceGroupRequest;
use Sire\Models\RecurrenceGroup;
use Sire\Models\Report;
use Sire\Services\SireCapaService;
use Sire\Services\SireRecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SireRecurrenceController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireRecurrenceService $recurrence,
        private readonly SireCapaService $capa,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $rank = "CASE recurrence_risk WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END";

        return $this->success(
            RecurrenceGroup::query()
                ->forTenant($this->sireUser()->tenantId)
                ->with(['owner:id,name'])
                ->when($request->boolean('open_only', true), fn ($q) => $q->where('is_closed', false))
                ->orderByRaw($rank)
                ->orderByDesc('occurrence_count')
                ->paginate((int) $request->integer('per_page', 25)),
        );
    }

    public function show(Request $request, RecurrenceGroup $group): JsonResponse
    {
        $this->assertTenantOwnership($group);

        return $this->success([
            'group'        => $group->load(['owner:id,name', 'rootCause', 'permanentFixRelease:id,version,name']),
            'occurrences'  => $group->occurrences()
                ->orderBy('occurred_at')
                ->get(['id', 'report_number', 'title', 'status', 'occurred_at', 'severity_id']),
            'actions'      => $group->actions()->with('owner:id,name')->get(),
            'open_actions' => $this->capa->openCountFor($group),
            'risk'         => $this->recurrence->assessRisk($group),
            // Exact-match candidates only. A human decides; nothing auto-assigns.
            'suggestions'  => $this->recurrence->suggestOccurrences($group),
        ]);
    }

    public function store(StoreRecurrenceGroupRequest $request): JsonResponse
    {
        return $this->success(
            $this->recurrence->create((int) $this->sireUser()->tenantId, $request->validated(), $this->sireUser()),
            201,
        );
    }

    public function addOccurrence(Request $request, RecurrenceGroup $group): JsonResponse
    {
        $this->assertTenantOwnership($group);

        $reportId = (int) $request->validate(['report_id' => ['required', 'integer']])['report_id'];
        $report = Report::query()->forTenant($this->sireUser()->tenantId)->findOrFail($reportId);

        return $this->success($this->recurrence->addOccurrence($group, $report, $this->sireUser()));
    }

    public function removeOccurrence(Request $request, RecurrenceGroup $group, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($group);
        $this->assertTenantOwnership($report);

        return $this->success($this->recurrence->removeOccurrence($group, $report, $this->sireUser()));
    }

    /** Recompute on demand. Every figure is derived; none is user-editable. */
    public function recompute(Request $request, RecurrenceGroup $group): JsonResponse
    {
        $this->assertTenantOwnership($group);

        return $this->success($this->recurrence->recompute($group));
    }
}
