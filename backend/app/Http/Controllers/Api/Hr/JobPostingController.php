<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrJobPosting;
use Illuminate\Http\Request;

class JobPostingController extends Controller
{
    public function index(Request $request)
    {
        $query = HrJobPosting::where('tenant_id', $request->user()->tenant_id);
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'              => 'required|string|max:200',
            'department'         => 'required|string|max:100',
            'location'           => 'required|string|max:100',
            'job_type'           => 'required|in:Full-time,Part-time,Contract,Internship,Remote',
            'posting_type'       => 'required|in:Internal,External,Both',
            'description'        => 'nullable|string',
            'requirements'       => 'nullable|string',
            'salary_from'        => 'nullable|numeric',
            'salary_to'          => 'nullable|numeric',
            'number_of_openings' => 'required|integer|min:1',
            'closing_date'       => 'nullable|date',
            'status'             => 'in:Active,Draft,Closed',
            'sources'            => 'nullable|array',
        ]);

        $job = HrJobPosting::create([...$validated, 'status' => $validated['status'] ?? 'Active']);
        return response()->json($job, 201);
    }

    public function show(HrJobPosting $jobPosting)
    {
        return response()->json($jobPosting->load('candidates'));
    }

    public function update(Request $request, HrJobPosting $jobPosting)
    {
        $jobPosting->update($request->all());
        return response()->json($jobPosting);
    }

    public function updateStatus(Request $request, HrJobPosting $jobPosting)
    {
        $request->validate(['status' => 'required|in:Active,Draft,Closed']);
        $jobPosting->update(['status' => $request->status]);
        return response()->json($jobPosting);
    }

    public function destroy(HrJobPosting $jobPosting)
    {
        $jobPosting->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Update external job ID (after manual posting to external platforms)
     */
    public function updateExternalId(Request $request, HrJobPosting $jobPosting)
    {
        $request->validate([
            'platform' => 'required|in:trulytalents,linkedin,naukri,indeed,monster',
            'external_id' => 'required|string|max:100',
        ]);

        $externalIds = $jobPosting->external_job_ids ?? [];
        $externalIds[$request->platform] = $request->external_id;
        
        $jobPosting->update(['external_job_ids' => $externalIds]);

        return response()->json([
            'message' => 'External job ID saved successfully',
            'external_ids' => $externalIds,
        ]);
    }
}
