<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\StoreReportRequest;
use Sire\Models\Report;
use Sire\Contracts\SireAttachmentProvider;
use Sire\Dto\SireAttachment;
use Sire\Services\SireContextService;
use Sire\Services\SireReportService;
use Sire\Services\SireWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — report endpoints.
 *
 * MERGE NOTE: slice 2 already defines this controller. Take `store()` and
 * `storeAttachment()` from here and leave the rest of that file alone.
 */
class ReportController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireReportService $reports,
        private readonly SireWorkflowService $workflow,
        private readonly SireContextService $context,
        private readonly SireAttachmentProvider $attachments,
    ) {
    }

    /**
     * Create a report — including one filed by the global Report Issue button.
     *
     * tenant_id and reporter_id come from the authenticated token. They are never
     * read from the request: StoreReportRequest strips them before validation,
     * and there is no code path here that would accept them.
     */
    public function store(StoreReportRequest $request): JsonResponse
    {
        $user     = $this->sireUser();
        $tenantId = (int) $user->tenantId;
        $data     = $request->validated();

        $report = DB::transaction(function () use ($data, $tenantId, $user) {
            $report = $this->reports->create($tenantId, $user, $data);

            if (! empty($data['context'])) {
                $this->context->capture($report, $data['context'], $tenantId);
            }

            // Report Issue files an issue; it does not leave a draft lying around.
            // Create + transition in one transaction so a failed submit rolls the
            // whole thing back rather than stranding a draft nobody sees.
            if ($data['submit'] ?? false) {
                $this->workflow->submit($report->fresh(), $user);
            }

            return $report->fresh();
        });

        return $this->success($this->reports->detail($report), 201);
    }

    /**
     * Attach evidence -- a Report Issue screenshot, or QA proof of a failure.
     *
     * The owner is the ROUTE-BOUND model, never a value from the request body,
     * so a file cannot be retargeted at another record by editing the payload.
     *
     * Validated twice on purpose: here, so the user gets a clear 422, and again
     * inside the adapter against the SNIFFED mime type, because an `accept`
     * attribute and a Content-Type header are both client-supplied hints.
     */
    public function storeAttachment(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report); // 404, not 403

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:8192'], // 8 MB; disk is tight on the box
        ]);

        $attachment = $this->attachments->store(
            $report,
            $request->file('file'),
            $this->sireUser(),
        );

        return $this->success($attachment->toArray(), 201);
    }

    /** Evidence already attached to this issue. */
    public function indexAttachments(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success(array_map(
            fn (SireAttachment $a) => $a->toArray(),
            $this->attachments->listFor($report),
        ));
    }
}
