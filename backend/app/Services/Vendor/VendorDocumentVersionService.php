<?php

namespace App\Services\Vendor;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Vendor\VendorDocument;
use App\Models\Vendor\VendorDocumentVersion;
use App\Support\Vendor\VendorDocumentStatus as Status;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document version history. Version capture is driven by VendorDocument model
 * events (see VendorDocument::booted) so the existing upload/review service is
 * never touched. Each version stores its own immutable copy of the file, so
 * previous versions survive even when the document's working file is replaced.
 */
class VendorDocumentVersionService
{
    private const DISK = 'vendor_docs';

    /**
     * Snapshot the document's current file as the next (current) version. Called
     * on create and whenever the file changes. Copies the working file to an
     * immutable version path so it persists independently.
     */
    public function capture(VendorDocument $doc, ?int $restoredFromVersionId = null): ?VendorDocumentVersion
    {
        if (! $doc->file_path || ! Storage::disk(self::DISK)->exists($doc->file_path)) {
            return null;
        }

        $next = (int) ($doc->versions()->max('version_no') ?? 0) + 1;

        $ext  = pathinfo($doc->file_path, PATHINFO_EXTENSION) ?: 'dat';
        $path = "versions/tenant_{$doc->tenant_id}/vendor_{$doc->vendor_id}/doc_{$doc->id}/v{$next}_".Str::random(6).'.'.$ext;
        Storage::disk(self::DISK)->copy($doc->file_path, $path);

        // Only one current version at a time.
        $doc->versions()->where('is_current', true)->update(['is_current' => false]);

        $version = VendorDocumentVersion::create([
            'tenant_id'                => $doc->tenant_id,
            'vendor_document_id'       => $doc->id,
            'version_no'               => $next,
            'file_path'                => $path,
            'original_name'            => $doc->original_name ?: basename($doc->file_path),
            'mime'                     => $doc->mime,
            'size'                     => $doc->size,
            'status_at_capture'        => $doc->status,
            'captured_by'              => Auth::id(),
            'is_current'               => true,
            'restored_from_version_id' => $restoredFromVersionId,
        ]);

        $doc->recordAudit('Document Version Created', null, null, [
            'version_no' => $next, 'restored_from_version_id' => $restoredFromVersionId,
        ]);

        return $version;
    }

    /** Resolve a previous version for download; audits the disclosure. */
    public function resolveDownload(VendorDocumentVersion $version, ?User $actor = null): array
    {
        if (! $version->file_path || ! Storage::disk(self::DISK)->exists($version->file_path)) {
            throw new BusinessException('Version file not found.', 404);
        }

        $path = Storage::disk(self::DISK)->path($version->file_path);

        $version->document?->recordAudit('Previous Version Downloaded', $actor, null, [
            'version_no' => $version->version_no,
        ]);

        return [
            'path'     => $path,
            'filename' => $version->original_name ?: basename($version->file_path),
            'mime'     => mime_content_type($path) ?: 'application/octet-stream',
        ];
    }

    /**
     * Restore a previous version: copy its file back onto the document and move
     * it to Pending (Under Review). The write triggers a fresh version capture,
     * so restore is itself recorded as a new current version.
     */
    public function restore(VendorDocument $doc, VendorDocumentVersion $version, User $actor): VendorDocument
    {
        if ((int) $version->vendor_document_id !== (int) $doc->id) {
            throw new BusinessException('Version does not belong to this document.', 404);
        }
        if (! Storage::disk(self::DISK)->exists($version->file_path)) {
            throw new BusinessException('Version file not found.', 404);
        }

        $ext     = pathinfo($version->file_path, PATHINFO_EXTENSION) ?: 'dat';
        $newPath = "tenant_{$doc->tenant_id}/vendor_{$doc->vendor_id}/{$doc->type}_restored_".Str::random(6).'_'.time().'.'.$ext;
        Storage::disk(self::DISK)->copy($version->file_path, $newPath);

        // Back to Pending for re-review. This file change triggers capture() via
        // the model event, creating the new current version.
        $doc->update([
            'file_path'     => $newPath,
            'original_name' => $version->original_name,
            'mime'          => $version->mime,
            'size'          => $version->size,
            'status'        => Status::UNDER_REVIEW,
            'remarks'       => null,
            'reviewed_by'   => null,
            'reviewed_at'   => null,
        ]);

        $current = $doc->versions()->where('is_current', true)->first();
        $current?->update(['restored_from_version_id' => $version->id]);

        $doc->recordAudit('Document Version Restored', $actor, null, [
            'from_version_no' => $version->version_no, 'to_version_no' => $current?->version_no,
        ]);

        Log::channel('tpv')->info('Vendor document version restored', [
            'document_id' => $doc->id, 'from_version' => $version->version_no,
        ]);

        return $doc->fresh();
    }
}
