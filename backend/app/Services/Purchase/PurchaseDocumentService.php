<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Repositories\Purchase\PurchaseDocumentRepository;
use App\Support\Purchase\PurchaseDocumentStatus as Status;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The statutory-document validation engine behind Purchase onboarding: upload
 * against the required doc matrix, admin review (approve/reject), resubmit on
 * rejection, and the completeness check the onboarding submission gates on.
 * Purchase-owned (purchase_documents, private `purchase_docs` disk) — never
 * touches vendor_documents or any TPV table.
 */
class PurchaseDocumentService
{
    private const DISK = 'purchase_docs';

    public const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png'];
    public const MAX_SIZE_KB   = 8192; // 8 MB

    public function __construct(private PurchaseDocumentRepository $documents)
    {
    }

    /** Every doc type the system understands (required sets + any extras). */
    public static function allowedTypes(): array
    {
        return array_values(array_unique([...PurchaseDocument::STANDARD_SET, ...PurchaseDocument::TEMPORARY_SET]));
    }

    /**
     * The required-vs-uploaded matrix for a vendor — drives the validation UI and
     * the onboarding step gates.
     */
    public function checklist(PurchaseVendor $vendor): array
    {
        $required = PurchaseDocument::requiredFor($vendor->vendor_type ?? 'standard');
        $docs     = $this->documents->forVendor($vendor)->keyBy('type');

        $rows = collect($required)->map(function ($type) use ($docs) {
            $doc = $docs->get($type);

            return [
                'type'          => $type,
                'type_label'    => PurchaseDocument::typeLabel($type),
                'uploaded'      => (bool) $doc,
                'status'        => $doc?->status,
                'status_label'  => $doc ? Status::label($doc->status) : 'Not Uploaded',
                'document_id'   => $doc?->id,
                'original_name' => $doc?->original_name,
                'remarks'       => $doc?->remarks,
                // Provenance for the admin read-only view: who supplied it, when
                // it arrived, and who reviewed it. The vendor is always the
                // uploader — admins never upload on their behalf.
                'uploaded_at'   => optional($doc?->created_at)->toIso8601String(),
                'reviewed_by'   => $doc?->reviewer?->name,
                'reviewed_at'   => optional($doc?->reviewed_at)->toIso8601String(),
                'expires_at'    => optional($doc?->expires_at)->toDateString(),
            ];
        })->values();

        $extras = $docs->reject(fn ($d) => in_array($d->type, $required, true))
            ->map(fn ($d) => [
                'type' => $d->type, 'type_label' => PurchaseDocument::typeLabel($d->type),
                'status' => $d->status, 'status_label' => Status::label($d->status), 'document_id' => $d->id,
                'original_name' => $d->original_name,
            ])->values();

        $approved = $rows->where('status', Status::APPROVED)->count();
        $rejected = $rows->where('status', Status::REJECTED)->count();
        $uploaded = $rows->where('uploaded', true)->count();

        return [
            'vendor_type' => $vendor->vendor_type,
            'required'    => $rows->all(),
            'extras'      => $extras->all(),
            'summary'     => [
                'required'  => count($required),
                'uploaded'  => $uploaded,
                'approved'  => $approved,
                'rejected'  => $rejected,
                'pending'   => $uploaded - $approved - $rejected,
                'progress_percent' => count($required) > 0 ? (int) round($approved / count($required) * 100) : 0,
            ],
            'complete'    => $approved === count($required),
        ];
    }

    /**
     * Upload (or replace) a document of a given type. Replacing removes the old
     * file and resets the review state so it's re-reviewed.
     */
    public function upload(PurchaseVendor $vendor, string $type, UploadedFile $file, User $actor): PurchaseDocument
    {
        $this->assertType($type);

        $existing = $this->documents->findByType($vendor, $type);
        if ($existing && $existing->isApproved()) {
            throw new BusinessException('This document is already approved. It cannot be replaced.');
        }

        $path = $this->store($vendor, $type, $file);

        $data = [
            'tenant_id'          => $vendor->tenant_id,
            'purchase_vendor_id' => $vendor->id,
            'type'          => $type,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'status'        => Status::UNDER_REVIEW,
            'remarks'       => null,
            'reviewed_by'   => null,
            'reviewed_at'   => null,
        ];

        if ($existing) {
            $this->deleteFile($existing->file_path);
            $existing->update($data);
            $doc = $existing;
        } else {
            $doc = PurchaseDocument::create($data);
        }

        $doc->recordAudit('Document Uploaded', $actor, null, ['type' => $type]);

        Log::channel('purchase')->info('Purchase document uploaded', [
            'document_id' => $doc->id, 'purchase_vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id, 'type' => $type,
        ]);

        return $doc->fresh();
    }

    /** Admin review — approve or reject a document with remarks. */
    public function review(PurchaseDocument $doc, string $decision, ?string $remarks, User $actor): PurchaseDocument
    {
        if ($doc->status === Status::APPROVED && $decision === 'approve') {
            throw new BusinessException('This document is already approved.');
        }
        if ($decision === 'reject' && ! $remarks) {
            throw new BusinessException('A reason is required to reject a document.');
        }

        $to = $decision === 'approve' ? Status::APPROVED : Status::REJECTED;

        $doc->update([
            'status'      => $to,
            'remarks'     => $remarks,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $doc->recordAudit($decision === 'approve' ? 'Document Approved' : 'Document Rejected', $actor, $remarks, [
            'type' => $doc->type, 'to' => $to,
        ]);

        Log::channel('purchase')->info('Purchase document reviewed', [
            'document_id' => $doc->id, 'tenant_id' => $doc->tenant_id, 'decision' => $decision,
        ]);

        return $doc->fresh(['reviewer:id,name']);
    }

    /** Replace a rejected document's file, returning it to review. */
    public function resubmit(PurchaseDocument $doc, UploadedFile $file, User $actor): PurchaseDocument
    {
        if ($doc->isApproved()) {
            throw new BusinessException('An approved document cannot be resubmitted.');
        }

        $vendor = $doc->vendor;
        $this->deleteFile($doc->file_path);
        $path = $this->store($vendor, $doc->type, $file);

        $doc->update([
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'status'        => Status::UNDER_REVIEW,
            'remarks'       => null,
            'reviewed_by'   => null,
            'reviewed_at'   => null,
        ]);

        $doc->recordAudit('Document Resubmitted', $actor, null, ['type' => $doc->type]);

        Log::channel('purchase')->info('Purchase document resubmitted', [
            'document_id' => $doc->id, 'tenant_id' => $doc->tenant_id,
        ]);

        return $doc->fresh();
    }

    public function resolveDownload(PurchaseDocument $doc): array
    {
        if (! $doc->file_path || ! Storage::disk(self::DISK)->exists($doc->file_path)) {
            throw new BusinessException('Document file not found.', 404);
        }

        $path = Storage::disk(self::DISK)->path($doc->file_path);

        return [
            'path'     => $path,
            'filename' => $doc->original_name ?: basename($doc->file_path),
            'mime'     => mime_content_type($path) ?: 'application/octet-stream',
        ];
    }

    public function destroy(PurchaseDocument $doc): void
    {
        if ($doc->isApproved()) {
            throw new BusinessException('An approved document cannot be deleted.');
        }

        $this->deleteFile($doc->file_path);
        $doc->delete();

        Log::channel('purchase')->info('Purchase document deleted', [
            'document_id' => $doc->id, 'tenant_id' => $doc->tenant_id,
        ]);
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function store(PurchaseVendor $vendor, string $type, UploadedFile $file): string
    {
        $ext  = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $name = $type.'_'.Str::random(6).'_'.time().'.'.$ext;
        $dir  = 'tenant_'.$vendor->tenant_id.'/vendor_'.$vendor->id;

        return $file->storeAs($dir, $name, self::DISK);
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, self::allowedTypes(), true)) {
            throw new BusinessException("Unknown document type: {$type}");
        }
    }
}
