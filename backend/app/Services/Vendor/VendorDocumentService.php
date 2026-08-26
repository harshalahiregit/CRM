<?php

namespace App\Services\Vendor;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Support\Vendor\VendorDocumentStatus as Status;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The statutory-document validation engine behind TPV onboarding: upload against
 * the required doc matrix, admin review (approve/reject), resubmit on rejection,
 * and the completeness check the onboarding submission gates on.
 */
class VendorDocumentService
{
    private const DISK = 'vendor_docs';

    public const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png'];
    public const MAX_SIZE_KB   = 8192; // 8 MB

    /** Every doc type the system understands (required sets + any extras). */
    public static function allowedTypes(): array
    {
        return array_values(array_unique([...VendorDocument::STANDARD_SET, ...VendorDocument::TEMPORARY_SET]));
    }

    /**
     * The required-vs-uploaded matrix for a vendor — drives the validation UI and
     * the onboarding step gates.
     */
    public function checklist(Vendor $vendor): array
    {
        $required = VendorDocument::requiredFor($vendor->vendor_type ?? 'standard');
        $docs     = $vendor->documents()->latest()->get()->keyBy('type');

        $rows = collect($required)->map(function ($type) use ($docs) {
            $doc = $docs->get($type);

            return [
                'type'          => $type,
                'type_label'    => VendorDocument::typeLabel($type),
                'uploaded'      => (bool) $doc,
                'status'        => $doc?->status,
                'status_label'  => $doc ? Status::label($doc->status) : 'Not Uploaded',
                'document_id'   => $doc?->id,
                'original_name' => $doc?->original_name,
                'remarks'       => $doc?->remarks,
                'reviewed_at'   => optional($doc?->reviewed_at)->toIso8601String(),
                'expires_at'    => optional($doc?->expires_at)->toDateString(),
            ];
        })->values();

        // Any uploaded docs outside the required set (e.g. extras a vendor added).
        $extras = $docs->reject(fn ($d) => in_array($d->type, $required, true))
            ->map(fn ($d) => [
                'type' => $d->type, 'type_label' => VendorDocument::typeLabel($d->type),
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
                // % of required documents approved — drives the Step-4 progress bar.
                'progress_percent' => count($required) > 0 ? (int) round($approved / count($required) * 100) : 0,
            ],
            // Onboarding may be finalised only when every required doc is approved.
            'complete'    => $approved === count($required),
        ];
    }

    /**
     * Upload (or replace) a document of a given type. Replacing removes the old
     * file and resets the review state so it's re-reviewed.
     */
    public function upload(Vendor $vendor, string $type, UploadedFile $file, User $actor): VendorDocument
    {
        $this->assertType($type);

        $existing = $vendor->documents()->where('type', $type)->first();
        if ($existing && $existing->isApproved()) {
            throw new BusinessException('This document is already approved. It cannot be replaced.');
        }

        $path = $this->store($vendor, $type, $file);

        $data = [
            'tenant_id'     => $vendor->tenant_id,
            'vendor_id'     => $vendor->id,
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
            $doc = VendorDocument::create($data);
        }

        $doc->recordAudit('Document Uploaded', $actor, null, ['type' => $type]);

        Log::channel('tpv')->info('Vendor document uploaded', [
            'document_id' => $doc->id, 'vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id, 'type' => $type,
        ]);

        return $doc->fresh();
    }

    /** Admin review — approve or reject a document with remarks. */
    /**
     * §30 — the verify step, distinct from approve. The reviewer confirms the
     * uploaded file is genuine and legible; the document moves to Verified and
     * awaits authority approval. Additive to the single-step approve/reject path.
     */
    public function verify(VendorDocument $doc, ?string $remarks, User $actor): VendorDocument
    {
        if ($doc->status === Status::APPROVED) {
            throw new BusinessException('An approved document is already past verification.');
        }

        $doc->update([
            'status'      => Status::VERIFIED,
            'remarks'     => $remarks ?: $doc->remarks,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ]);

        $doc->recordAudit('Document Verified', $actor, $remarks, ['type' => $doc->type]);

        return $doc->fresh(['reviewer:id,name']);
    }

    public function review(VendorDocument $doc, string $decision, ?string $remarks, User $actor): VendorDocument
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

        Log::channel('tpv')->info('Vendor document reviewed', [
            'document_id' => $doc->id, 'tenant_id' => $doc->tenant_id, 'decision' => $decision,
        ]);

        return $doc->fresh(['reviewer:id,name']);
    }

    /** Replace a rejected document's file, returning it to review. */
    public function resubmit(VendorDocument $doc, UploadedFile $file, User $actor): VendorDocument
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

        Log::channel('tpv')->info('Vendor document resubmitted', [
            'document_id' => $doc->id, 'tenant_id' => $doc->tenant_id,
        ]);

        return $doc->fresh();
    }

    public function resolveDownload(VendorDocument $doc): array
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

    public function destroy(VendorDocument $doc): void
    {
        if ($doc->isApproved()) {
            throw new BusinessException('An approved document cannot be deleted.');
        }

        $this->deleteFile($doc->file_path);
        $doc->delete();

        Log::channel('tpv')->info('Vendor document deleted', [
            'document_id' => $doc->id, 'tenant_id' => $doc->tenant_id,
        ]);
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function store(Vendor $vendor, string $type, UploadedFile $file): string
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
