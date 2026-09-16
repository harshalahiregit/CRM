<?php

namespace Sire\Contracts;

use Sire\Dto\SireAttachment;
use Sire\Dto\SireUserIdentity;
use Illuminate\Http\UploadedFile;

/**
 * SIRE SDK — EVIDENCE.
 *
 * Report Issue screenshots and QA proof. SIRE stores nothing itself and owns no
 * attachment table; files go wherever the host already puts them — local disk,
 * S3, an existing attachment service — and inherit its limits, scanning,
 * retention and access rules for free.
 *
 * SECURITY IS THE IMPLEMENTATION'S JOB, AND SIRE CANNOT DO IT FOR YOU
 *
 * A host implementation MUST enforce:
 *
 *   - tenant isolation — a listing returns one tenant's files, never a join
 *     across tenants;
 *   - authorization — the caller may see this owner's evidence;
 *   - type and size limits, checked against the SNIFFED mime type. An `accept`
 *     attribute and a Content-Type header are both client-supplied hints;
 *   - safe filenames — never trust the client's, never interpolate it into a
 *     path;
 *   - no arbitrary path access — an attachment id is an opaque handle, not a
 *     path. If ids are paths, `../../.env` is a valid attachment id.
 *
 * The returned descriptor carries no disk name, storage key or filesystem path.
 * `url` should be whatever access-controlled URL the host already issues.
 */
interface SireAttachmentProvider
{
    /**
     * Store a file against a SIRE record.
     *
     * $owner is always the ROUTE-BOUND model, never a value from the request
     * body — that is what stops a file being retargeted at another record by
     * editing a payload.
     *
     * @param  array<string, mixed> $meta host-defined extras
     */
    public function store(object $owner, UploadedFile $file, SireUserIdentity $user, array $meta = []): SireAttachment;

    /**
     * Everything attached to one record, oldest first.
     *
     * @return array<int, SireAttachment>
     */
    public function listFor(object $owner): array;

    /** One descriptor, or null when absent or not this tenant's. */
    public function find(int|string $attachmentId): ?SireAttachment;

    /**
     * Remove one. Subject to the host's own rules — SIRE never hard-deletes
     * evidence on its own initiative.
     */
    public function delete(int|string $attachmentId, SireUserIdentity $user): void;
}
