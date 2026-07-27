<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseDocumentVersion;
use App\Models\User;
use App\Support\Purchase\PurchaseDocumentStatus as Status;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Purchase document version history. Version capture is driven by PurchaseDocument
 * model events (see PurchaseDocument::booted). Each version stores its own
 * immutable copy of the file, so previous versions survive when the working file
 * is replaced. Purchase-owned — never touches vendor_document_versions or TPV.
 */
class PurchaseDocumentVersionService
{
    private const DISK = 'purchase_docs';

    /** Snapshot the document's current file as the next (current) version. */
    public function capture(PurchaseDocument $doc, ?int $restoredFromVersionId = null): ?PurchaseDocumentVersion
    {
        if (! $doc->file_path || ! Storage::disk(self::DISK)->exists($doc->file_path)) {
            return null;
        }

        $next = (int) ($doc->versions()->max('version_no') ?? 0) + 1;

        $ext  = pathinfo($doc->file_path, PATHINFO_EXTENSION) ?: 'dat';
        $path = "versions/tenant_{$doc->tenant_id}/vendor_{$doc->purchase_vendor_id}/doc_{$doc->id}/v{$next}_".Str::random(6).'.'.$ext;
        Storage::disk(self::DISK)->copy($doc->file_path, $path);

        // Only one current version at a time.
        $doc->versions()->where('is_current', true)->update(['is_current' => false]);

        $version = PurchaseDocumentVersion::create([
            'tenant_id'                => $doc->tenant_id,
            'purchase_document_id'     => $doc->id,
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
    public function resolveDownload(PurchaseDocumentVersion $version, ?User $actor = null): array
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
     * it to Under Review. The write triggers a fresh version capture.
     */
    public function restore(PurchaseDocument $doc, PurchaseDocumentVersion $version, User $actor): PurchaseDocument
    {
        if ((int) $version->purchase_document_id !== (int) $doc->id) {
            throw new BusinessException('Version does not belong to this document.', 404);
        }
        if (! Storage::disk(self::DISK)->exists($version->file_path)) {
            throw new BusinessException('Version file not found.', 404);
        }

        $ext     = pathinfo($version->file_path, PATHINFO_EXTENSION) ?: 'dat';
        $newPath = "tenant_{$doc->tenant_id}/vendor_{$doc->purchase_vendor_id}/{$doc->type}_restored_".Str::random(6).'_'.time().'.'.$ext;
        Storage::disk(self::DISK)->copy($version->file_path, $newPath);

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

        Log::channel('purchase')->info('Purchase document version restored', [
            'document_id' => $doc->id, 'from_version' => $version->version_no,
        ]);

        return $doc->fresh();
    }
}
