<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrCandidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResumeController extends Controller
{
    /** Allowed MIME types */
    private const ALLOWED_MIMES = ['pdf', 'doc', 'docx'];
    private const MAX_SIZE_KB   = 5120; // 5 MB

    /**
     * POST /api/hr/candidates/{candidate}/resume
     * Upload or replace a candidate's resume.
     */
    public function upload(Request $request, HrCandidate $candidate)
    {
        // Ensure candidate belongs to caller's tenant
        if ($candidate->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'resume' => [
                'required',
                'file',
                'max:' . self::MAX_SIZE_KB,
                'mimes:pdf,doc,docx',
            ],
        ], [
            'resume.max'   => 'Resume must be under 5 MB.',
            'resume.mimes' => 'Only PDF, DOC, and DOCX files are allowed.',
        ]);

        // Delete old resume if one exists
        if ($candidate->resume_path && Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            Storage::disk('hr_resumes')->delete($candidate->resume_path);
        }

        $file      = $request->file('resume');
        $ext       = $file->getClientOriginalExtension();
        $safeName  = Str::slug($candidate->name) . '_' . $candidate->id . '_' . time() . '.' . $ext;
        $tenantDir = 'tenant_' . $candidate->tenant_id;

        // Store in private disk: hr/resumes/tenant_X/filename
        $path = $file->storeAs($tenantDir, $safeName, 'hr_resumes');

        // Save path on candidate
        $candidate->update(['resume_path' => $path]);

        return response()->json([
            'success'     => true,
            'resume_path' => $path,
            'filename'    => $safeName,
            'size_kb'     => round($file->getSize() / 1024, 1),
            'mime'        => $file->getClientMimeType(),
        ]);
    }

    /**
     * GET /api/hr/candidates/{candidate}/resume
     * Stream/download the candidate's resume.
     */
    public function download(Request $request, HrCandidate $candidate)
    {
        // Ensure candidate belongs to caller's tenant
        if ($candidate->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$candidate->resume_path) {
            return response()->json(['message' => 'No resume uploaded for this candidate.'], 404);
        }

        if (!Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            return response()->json(['message' => 'Resume file not found on disk.'], 404);
        }

        $path     = Storage::disk('hr_resumes')->path($candidate->resume_path);
        $filename = basename($candidate->resume_path);
        $mime     = mime_content_type($path) ?: 'application/octet-stream';

        return response()->download($path, $filename, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * DELETE /api/hr/candidates/{candidate}/resume
     * Remove a candidate's resume.
     */
    public function delete(Request $request, HrCandidate $candidate)
    {
        if ($candidate->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($candidate->resume_path && Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            Storage::disk('hr_resumes')->delete($candidate->resume_path);
        }

        $candidate->update(['resume_path' => null]);

        return response()->json(['success' => true, 'message' => 'Resume deleted.']);
    }
}
