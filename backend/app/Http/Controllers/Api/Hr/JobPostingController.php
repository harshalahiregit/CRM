<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreJobPostingRequest;
use App\Http\Requests\Hr\UpdateJobPostingExternalIdRequest;
use App\Http\Requests\Hr\UpdateJobPostingRequest;
use App\Http\Requests\Hr\UpdateJobPostingStatusRequest;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\JobPostingService;
use App\Services\Hr\JobPublishingService;
use Illuminate\Http\Request;

class JobPostingController extends Controller
{
    public function __construct(
        private JobPostingService $jobPostingService,
        private JobPublishingService $jobPublishingService,
    ) {
    }

    /* GET /api/hr/jobs/channels — available distribution channels */
    public function channels()
    {
        return response()->json($this->jobPublishingService->channels());
    }

    /* POST /api/hr/jobs/{jobPosting}/publish-to  { channel } */
    public function publishTo(Request $request, HrJobPosting $jobPosting)
    {
        $channel = $request->validate(['channel' => 'required|string'])['channel'];

        return response()->json($this->jobPublishingService->publish($jobPosting, $channel, $request->user()));
    }

    /* POST /api/hr/jobs/{jobPosting}/publish-channels  { channels: [] } */
    public function publishChannels(Request $request, HrJobPosting $jobPosting)
    {
        $channels = $request->validate([
            'channels'   => 'required|array|min:1',
            'channels.*' => 'string',
        ])['channels'];

        return response()->json($this->jobPublishingService->publishMany($jobPosting, $channels, $request->user()));
    }

    /* DELETE /api/hr/jobs/{jobPosting}/publish-to/{channel} */
    public function unpublishFrom(Request $request, HrJobPosting $jobPosting, string $channel)
    {
        $this->jobPublishingService->unpublish($jobPosting, $channel, $request->user());

        return response()->json(['message' => 'Removed from channel']);
    }

    /**
     * POST /api/hr/jobs/{jobPosting}/sync/{channel}
     *
     * #13 — ask the channel what it currently thinks of the posting and reconcile
     * the publication ledger. Refused for channels that cannot report status.
     */
    public function syncChannel(Request $request, HrJobPosting $jobPosting, string $channel)
    {
        return response()->json($this->jobPublishingService->sync($jobPosting, $channel, $request->user()));
    }

    /* GET /api/hr/jobs */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'designation', 'designation_id', 'hiring_manager_id']);
        $filters['designation_name'] = \App\Support\Hr\DesignationFilter::resolve($filters, $request->user()->tenant_id);

        return response()->json($this->jobPostingService->list($request->user(), $filters));
    }

    /* GET /api/hr/jobs/stats */
    public function stats(Request $request)
    {
        return response()->json($this->jobPostingService->stats($request->user()->tenant_id));
    }

    /* POST /api/hr/jobs/analyze-jd — AI JD quality score + suggestions (heuristic) */
    public function analyzeJd(Request $request)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised');
        $data = $request->validate([
            'title'        => 'nullable|string|max:200',
            'department'   => 'nullable|string|max:100',
            'description'  => 'nullable|string',
            'requirements' => 'nullable|string',
            'improve'      => 'nullable|boolean',
        ]);

        $analyzer = new \App\Support\Hr\JdAnalyzer;
        $analysis = $analyzer->analyze($data['title'] ?? '', $data['description'] ?? '', $data['requirements'] ?? '', $data['department'] ?? null);

        if (! empty($data['improve'])) {
            $analysis['improved_description'] = $analyzer->improve($data['title'] ?? 'this role', $data['description'] ?? '', $data['requirements'] ?? '', $data['department'] ?? null, $analysis);
        }

        return response()->json($analysis);
    }

    /* POST /api/hr/jobs/bulk — apply a lifecycle action to many postings */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action'  => 'required|in:publish,pause,close,delete',
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'integer',
        ]);

        return response()->json($this->jobPostingService->bulk($data['action'], $data['ids'], $request->user()));
    }

    /* POST /api/hr/jobs */
    public function store(StoreJobPostingRequest $request)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to create job postings');

        $job = $this->jobPostingService->create($request->validated(), $request->user());

        return response()->json($job, 201);
    }

    /* GET /api/hr/jobs/{jobPosting} — posting + request info + progress + timeline */
    public function show(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->find($jobPosting, $request->user()));
    }

    /* PUT /api/hr/jobs/{jobPosting} */
    public function update(UpdateJobPostingRequest $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->update($jobPosting, $request->validated(), $request->user()));
    }

    /* PATCH /api/hr/jobs/{jobPosting}/status */
    public function updateStatus(UpdateJobPostingStatusRequest $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->updateStatus($jobPosting, $request->validated('status'), $request->user()));
    }

    /* Lifecycle actions */
    public function publish(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->publish($jobPosting, $request->user()));
    }

    public function unpublish(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->unpublish($jobPosting, $request->user()));
    }

    public function pause(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->pause($jobPosting, $request->user()));
    }

    public function close(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->close($jobPosting, $request->user(), $request->input('remarks')));
    }

    public function cancel(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->cancel($jobPosting, $request->user(), $request->input('remarks')));
    }

    public function duplicate(Request $request, HrJobPosting $jobPosting)
    {
        return response()->json($this->jobPostingService->duplicate($jobPosting, $request->user()), 201);
    }

    /* DELETE /api/hr/jobs/{jobPosting} */
    public function destroy(Request $request, HrJobPosting $jobPosting)
    {
        $this->jobPostingService->destroy($jobPosting, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* PATCH /api/hr/jobs/{jobPosting}/external-id */
    public function updateExternalId(UpdateJobPostingExternalIdRequest $request, HrJobPosting $jobPosting)
    {
        $externalIds = $this->jobPostingService->updateExternalId(
            $jobPosting,
            $request->validated('platform'),
            $request->validated('external_id'),
            $request->user()
        );

        return response()->json(['message' => 'External job ID saved successfully', 'external_ids' => $externalIds]);
    }
}
