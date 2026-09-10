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
use Illuminate\Support\Facades\Storage;

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
            //
            // Nothing to do to honour that: there IS no draft state. create()
            // stamps SireWorkflow::INITIAL ('new'), which is the filed state, and
            // the workflow has no submit transition out of it -- the next move is
            // triage, by someone else. submit is still accepted so the Report
            // Issue payload keeps working, it simply describes what already
            // happened.
            //
            // This replaced a call to SireWorkflowService::submit(), a method that
            // does not exist on that service. Every report filed from the button
            // sends submit:true, so the button 500'd on every use.

            return $report->fresh();
        });

        return $this->success($this->reports->detail($report), 201);
    }

    /**
     * One issue, with everything the detail screen renders.
     *
     * The file's MERGE NOTE says to take only store() and storeAttachment() from
     * this slice and leave the rest alone -- but the slice that was meant to
     * carry show() never landed, so IssueDetailPage called an endpoint that did
     * not exist. Added here, in this controller's own idiom.
     *
     * Ownership is asserted before anything is read, and the miss is a 404 rather
     * than a 403: a 403 would confirm the id belongs to somebody, which tells a
     * caller in another tenant something true about data they may not see.
     */
    public function show(Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report); // 404, not 403

        return $this->success($this->reports->detail($report, $this->sireUser()));
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

    /**
     * Stream one piece of evidence.
     *
     * The attachments disk is PRIVATE and has no public URL, so without this the
     * screenshot a reporter attached could be listed but never actually seen --
     * the descriptor's url pointed at /storage/..., which has no symlink and
     * would not be safe if it did.
     *
     * Tenant ownership is asserted on the REPORT first, and the file is then
     * resolved through the provider's own listing for that report. An id is a
     * path here, so it is never concatenated into a disk read directly.
     */
    public function downloadAttachment(Request $request, Report $report, string $attachment)
    {
        $this->assertTenantOwnership($report); // 404, not 403

        $id = urldecode($attachment);

        // Membership, not string surgery: the file must be one this report
        // actually owns, which also makes traversal unexpressible.
        $match = null;

        foreach ($this->attachments->listFor($report) as $candidate) {
            if ((string) $candidate->id === $id) {
                $match = $candidate;
                break;
            }
        }

        abort_if($match === null, 404);

        $disk = Storage::disk((string) config('sire.attachments.disk'));

        abort_unless($disk->exists($id), 404);

        return $disk->response($id, $match->name, [
            'Content-Type'        => $match->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($match->name).'"',
        ]);
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
