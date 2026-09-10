<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\MarkRegressionRequest;
use Sire\Http\Requests\StoreReportLinkRequest;
use Sire\Models\Report;
use Sire\Models\ReportLink;
use Sire\Services\SireDuplicateService;
use Sire\Services\SireRegressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Issue-to-issue relationships: duplicates, links and regressions. */
class SireRelationController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireDuplicateService $duplicates,
        private readonly SireRegressionService $regressions,
    ) {
    }

    /** Everything related to this issue, from both directions. */
    public function index(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $canonical = $this->duplicates->resolveCanonical($report);

        return $this->success([
            // Nothing is deleted: a duplicate still resolves, still shows where the
            // work went, and its own duplicates are still countable.
            'duplicate_of'   => $report->duplicateOf,
            'canonical'      => $canonical['canonical']->id === $report->id ? null : $canonical['canonical'],
            'chain_broken'   => $canonical['cycle'],
            'duplicates'     => $this->duplicates->membersOf($report),
            'links'          => $report->links()->with('toReport:id,report_number,title,status')->get(),
            'inbound_links'  => $report->inboundLinks()->with('fromReport:id,report_number,title,status')->get(),
            'regression_of'  => $report->regressionOf,
            'caused_by'      => $report->causedByRelease,
        ]);
    }

    public function storeLink(StoreReportLinkRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);
        $data = $request->validated();

        $target = Report::query()->forTenant($this->sireUser()->tenantId)->findOrFail($data['to_report_id']);

        $link = ReportLink::firstOrCreate([
            'tenant_id'      => $report->tenant_id,
            'from_report_id' => $report->id,
            'to_report_id'   => $target->id,
            'link_type'      => $data['link_type'],
        ], [
            'note'       => $data['note'] ?? null,
            'created_by' => $this->sireUser()->id,
        ]);

        return $this->success($link, 201);
    }

    public function destroyLink(Request $request, ReportLink $link): JsonResponse
    {
        $this->assertTenantOwnership($link);
        $link->delete();

        return $this->success(null, 204);
    }

    public function markRegression(MarkRegressionRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->regressions->mark($report, $request->validated(), $this->sireUser()));
    }

    public function clearRegression(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->success($this->regressions->clear($report, $reason, $this->sireUser()));
    }
}
