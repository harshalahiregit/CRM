<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Typed candidate documents (offer letters, ID proofs, certificates, extra
 * resumes). Files live on the private `hr_documents` disk, partitioned per
 * tenant. Tenant scoping is enforced by the controller (assertTenant); this
 * service owns storage, metadata and audit entries.
 */
class CandidateDocumentService
{
    public const DISK          = 'hr_documents';
    public const ALLOWED_MIMES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    public const MAX_SIZE_KB   = 10240; // 10 MB

    public function list(HrCandidate $candidate): Collection
    {
        return $candidate->documents()->get();
    }

    public function upload(HrCandidate $candidate, UploadedFile $file, string $type, ?User $actor): HrCandidateDocument
    {
        $type = in_array($type, HrCandidateDocument::TYPES, true) ? $type : 'other';

        $ext      = strtolower($file->getClientOriginalExtension());
        $safeName = Str::slug($candidate->name).'_'.$type.'_'.$candidate->id.'_'.time().'.'.$ext;
        $tenantDir = 'tenant_'.$candidate->tenant_id;

        $path = $file->storeAs($tenantDir, $safeName, self::DISK);

        $document = $candidate->documents()->create([
            'tenant_id'     => $candidate->tenant_id,
            'uploaded_by'   => $actor?->id,
            'type'          => $type,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size_kb'       => (int) round($file->getSize() / 1024),
            'mime'          => $file->getClientMimeType(),
        ]);

        $candidate->recordAudit('Document uploaded: '.$type, $actor, null, ['document' => $file->getClientOriginalName()]);

        Log::channel('hr')->info('Candidate document uploaded', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'document_id' => $document->id]);

        return $document;
    }

    public function resolveDownload(HrCandidateDocument $document): array
    {
        if (! Storage::disk(self::DISK)->exists($document->path)) {
            throw new BusinessException('Document file not found on disk.', 404);
        }

        $path = Storage::disk(self::DISK)->path($document->path);

        return [
            'path'     => $path,
            'filename' => $document->original_name,
            'mime'     => mime_content_type($path) ?: ($document->mime ?: 'application/octet-stream'),
        ];
    }

    public function delete(HrCandidate $candidate, HrCandidateDocument $document): void
    {
        if ($document->path && Storage::disk(self::DISK)->exists($document->path)) {
            Storage::disk(self::DISK)->delete($document->path);
        }

        $candidate->recordAudit('Document removed: '.$document->type, null, null, ['document' => $document->original_name]);

        $document->delete();

        Log::channel('hr')->info('Candidate document deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'document_id' => $document->id]);
    }
}
