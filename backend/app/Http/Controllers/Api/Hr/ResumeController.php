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
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $result = $this->resumeService->upload($candidate, $request->user()->tenant_id, $request->file('resume'));

        return response()->json($result);
    }

    /**
     * GET /api/hr/candidates/{candidate}/resume
     * Stream/download the candidate's resume.
     */
    public function download(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

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
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $this->resumeService->delete($candidate, $request->user()->tenant_id);

        return response()->json(['success' => true, 'message' => 'Resume deleted.']);
    }

    /**
     * POST /api/hr/candidates/{candidate}/resume/extract
     *
     * #15 — re-read the stored resume and fill any blank Department / Designation /
     * Present Company / Reference. Never overwrites a value already present.
     */
    public function extract(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        return response()->json(
            $this->resumeService->extract($candidate, $request->user()->tenant_id)
        );
    }

    private function assertTenant(Request $request, HrCandidate $candidate): void
    {
        abort_unless((int) $candidate->tenant_id === (int) $request->user()->tenant_id, 404, 'Candidate not found');
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage resumes');
    }
}
