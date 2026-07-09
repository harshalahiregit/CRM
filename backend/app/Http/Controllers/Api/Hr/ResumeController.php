<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UploadResumeRequest;
use App\Models\Hr\HrCandidate;
use App\Services\Hr\ResumeService;
use Illuminate\Http\Request;

class ResumeController extends Controller
{
    public function __construct(private ResumeService $resumeService)
    {
    }

    /**
     * POST /api/hr/candidates/{candidate}/resume
     * Upload or replace a candidate's resume.
     */
    public function upload(UploadResumeRequest $request, HrCandidate $candidate)
    {
        $result = $this->resumeService->upload($candidate, $request->user()->tenant_id, $request->file('resume'));

        return response()->json($result);
    }

    /**
     * GET /api/hr/candidates/{candidate}/resume
     * Stream/download the candidate's resume.
     */
    public function download(Request $request, HrCandidate $candidate)
    {
        $file = $this->resumeService->resolveDownload($candidate, $request->user()->tenant_id);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    /**
     * DELETE /api/hr/candidates/{candidate}/resume
     * Remove a candidate's resume.
     */
    public function delete(Request $request, HrCandidate $candidate)
    {
        $this->resumeService->delete($candidate, $request->user()->tenant_id);

        return response()->json(['success' => true, 'message' => 'Resume deleted.']);
    }
}
