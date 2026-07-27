<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrHiringRequestDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Request-scoped documents (JD/NDA/PO/Agreement/Scope/SOW/Other). Mirrors
 * CandidateDocumentService exactly — same private hr_documents disk, same
 * per-tenant partitioning and inline streaming — so no storage logic is
 * reinvented; only the owning entity differs.
 */
class HiringRequestDocumentService
{
    public const DISK          = 'hr_documents';
    public const ALLOWED_MIMES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
    public const MAX_SIZE_KB   = 10240; // 10 MB

    public function list(HrHiringRequest $request): Collection
    {
        return $request->documents()->with('uploader:id,name')->get();
    }

    public function upload(HrHiringRequest $request, UploadedFile $file, string $type, string $uploaderKind, ?User $actor): HrHiringRequestDocument
    {
        $type = in_array($type, HrHiringRequestDocument::TYPES, true) ? $type : 'other';

        $ext      = strtolower($file->getClientOriginalExtension());
        $safeName = 'req'.$request->id.'_'.$type.'_'.time().'.'.$ext;
        $path     = $file->storeAs('tenant_'.$request->tenant_id.'/hiring_requests', $safeName, self::DISK);

        $document = $request->documents()->create([
            'tenant_id'     => $request->tenant_id,
            'uploader_kind' => $uploaderKind,
            'uploaded_by'   => $actor?->id,
            'type'          => $type,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size_kb'       => (int) round($file->getSize() / 1024),
            'mime'          => $file->getClientMimeType(),
        ]);

        $request->recordAudit('Document uploaded: '.strtoupper($type), $actor, $file->getClientOriginalName(), ['client_visible' => true]);

        return $document->load('uploader:id,name');
    }

    public function resolveDownload(HrHiringRequestDocument $document): array
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

    public function delete(HrHiringRequest $request, HrHiringRequestDocument $document): void
    {
        if ($document->path && Storage::disk(self::DISK)->exists($document->path)) {
            Storage::disk(self::DISK)->delete($document->path);
        }
        $request->recordAudit('Document removed: '.strtoupper($document->type), null, $document->original_name);
        $document->delete();
    }
}
