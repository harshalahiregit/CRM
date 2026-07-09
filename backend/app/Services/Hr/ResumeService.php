<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Hr\HrCandidate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResumeService
{
    public const ALLOWED_MIMES = ['pdf', 'doc', 'docx'];
    public const MAX_SIZE_KB   = 5120; // 5 MB

    public function upload(HrCandidate $candidate, int $tenantId, UploadedFile $file): array
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if ($candidate->resume_path && Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            Storage::disk('hr_resumes')->delete($candidate->resume_path);
        }

        $ext       = $file->getClientOriginalExtension();
        $safeName  = Str::slug($candidate->name).'_'.$candidate->id.'_'.time().'.'.$ext;
        $tenantDir = 'tenant_'.$candidate->tenant_id;

        $path = $file->storeAs($tenantDir, $safeName, 'hr_resumes');

        $candidate->update(['resume_path' => $path]);

        Log::channel('hr')->info('Resume uploaded', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);

        return [
            'success'     => true,
            'resume_path' => $path,
            'filename'    => $safeName,
            'size_kb'     => round($file->getSize() / 1024, 1),
            'mime'        => $file->getClientMimeType(),
        ];
    }

    public function resolveDownload(HrCandidate $candidate, int $tenantId): array
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if (! $candidate->resume_path) {
            throw new BusinessException('No resume uploaded for this candidate.', 404);
        }

        if (! Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            throw new BusinessException('Resume file not found on disk.', 404);
        }

        $path     = Storage::disk('hr_resumes')->path($candidate->resume_path);
        $filename = basename($candidate->resume_path);
        $mime     = mime_content_type($path) ?: 'application/octet-stream';

        return ['path' => $path, 'filename' => $filename, 'mime' => $mime];
    }

    public function delete(HrCandidate $candidate, int $tenantId): void
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if ($candidate->resume_path && Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            Storage::disk('hr_resumes')->delete($candidate->resume_path);
        }

        $candidate->update(['resume_path' => null]);

        Log::channel('hr')->info('Resume deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);
    }

    private function assertBelongsToTenant(HrCandidate $candidate, int $tenantId): void
    {
        if ($candidate->tenant_id !== $tenantId) {
            Log::channel('hr')->warning('Resume action rejected: tenant mismatch', ['candidate_id' => $candidate->id, 'candidate_tenant_id' => $candidate->tenant_id, 'requester_tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}
