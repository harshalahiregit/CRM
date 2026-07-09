<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreJobPostingRequest;
use App\Http\Requests\Hr\UpdateJobPostingExternalIdRequest;
use App\Http\Requests\Hr\UpdateJobPostingStatusRequest;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\JobPostingService;
use Illuminate\Http\Request;

class JobPostingController extends Controller
{
    public function __construct(private JobPostingService $jobPostingService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->jobPostingService->list($request->user()->tenant_id, $request->only('status'))
        );
    }

    public function store(StoreJobPostingRequest $request)
    {
        $job = $this->jobPostingService->create($request->validated());

        return response()->json($job, 201);
    }

    public function show(HrJobPosting $jobPosting)
    {
        return response()->json($jobPosting->load('candidates'));
    }

    public function update(Request $request, HrJobPosting $jobPosting)
    {
        $updated = $this->jobPostingService->update($jobPosting, $request->all());

        return response()->json($updated);
    }

    public function updateStatus(UpdateJobPostingStatusRequest $request, HrJobPosting $jobPosting)
    {
        $updated = $this->jobPostingService->updateStatus($jobPosting, $request->validated('status'));

        return response()->json($updated);
    }

    public function destroy(HrJobPosting $jobPosting)
    {
        $this->jobPostingService->destroy($jobPosting);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Update external job ID (after manual posting to external platforms)
     */
    public function updateExternalId(UpdateJobPostingExternalIdRequest $request, HrJobPosting $jobPosting)
    {
        $externalIds = $this->jobPostingService->updateExternalId(
            $jobPosting,
            $request->validated('platform'),
            $request->validated('external_id')
        );

        return response()->json([
            'message'      => 'External job ID saved successfully',
            'external_ids' => $externalIds,
        ]);
    }
}
