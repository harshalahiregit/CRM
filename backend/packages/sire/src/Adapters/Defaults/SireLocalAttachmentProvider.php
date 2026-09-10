<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireAttachmentProvider;
use Sire\Dto\SireAttachment;
use Sire\Dto\SireUserIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The shipped attachment provider: files on a Laravel disk, no attachment table.
 *
 * Screenshots from Report Issue and QA evidence land under
 * sire/{tenant}/{owner-type}/{owner-id}/ on the configured disk. Metadata is
 * derived from the path and the disk, which is why SIRE adds no attachment
 * schema — a host that already has one implements SireAttachmentProvider and
 * SIRE evidence inherits its limits, scanning, retention and access rules.
 *
 * TENANT IN THE PATH IS THE POINT
 *
 * Ownership is structural: a file for tenant 7 physically cannot be listed by a
 * request scoped to tenant 8, because listFor() only ever reads one tenant's
 * directory. That is a stronger guarantee than a WHERE clause somebody has to
 * remember to write.
 *
 * TYPE AND SIZE ARE CHECKED SERVER-SIDE, against the sniffed mime type. An
 * `accept` attribute and a Content-Type header are both client-supplied hints.
 *
 * IDS ARE PATHS HERE, WHICH IS EXACTLY WHY delete() GUARDS THEM. Without the
 * prefix and traversal checks, `../../.env` would be a valid attachment id.
 */
class SireLocalAttachmentProvider implements SireAttachmentProvider
{
    public function store(object $owner, UploadedFile $file, SireUserIdentity $user, array $meta = []): SireAttachment
    {
        $this->guard($file);

        $disk = Storage::disk((string) config('sire.attachments.disk'));
        $directory = $this->directory($owner);

        // Never the client's filename: it is attacker-controlled and ends up in a
        // path. The original is preserved in the descriptor for display only.
        $stored = sprintf(
            '%s-%s.%s',
            now()->format('YmdHis'),
            substr(bin2hex(random_bytes(8)), 0, 12),
            preg_replace('/[^a-z0-9]/i', '', $file->getClientOriginalExtension()) ?: 'bin',
        );

        $disk->putFileAs($directory, $file, $stored);

        return new SireAttachment(
            id: $directory.'/'.$stored,
            name: $file->getClientOriginalName(),
            size: (int) $file->getSize(),
            mime: $file->getMimeType(),
            url: $this->urlFor($directory.'/'.$stored),
            createdAt: now()->toIso8601String(),
            uploadedBy: $user->id,
        );
    }

    public function listFor(object $owner): array
    {
        $disk = Storage::disk((string) config('sire.attachments.disk'));
        $directory = $this->directory($owner);

        if (! $disk->exists($directory)) {
            return [];
        }

        return collect($disk->files($directory))
            ->map(fn (string $path) => new SireAttachment(
                id: $path,
                name: basename($path),
                size: (int) $disk->size($path),
                mime: $disk->mimeType($path) ?: null,
                url: $this->urlFor($path),
                createdAt: date(DATE_ATOM, $disk->lastModified($path)),
            ))
            ->sortBy(fn (SireAttachment $a) => $a->createdAt)
            ->values()
            ->all();
    }

    public function find(int|string $attachmentId): ?SireAttachment
    {
        $path = (string) $attachmentId;

        if (! $this->isSafePath($path)) {
            return null;
        }

        $disk = Storage::disk((string) config('sire.attachments.disk'));

        if (! $disk->exists($path)) {
            return null;
        }

        return new SireAttachment(
            id: $path,
            name: basename($path),
            size: (int) $disk->size($path),
            mime: $disk->mimeType($path) ?: null,
            url: $this->urlFor($path),
            createdAt: date(DATE_ATOM, $disk->lastModified($path)),
        );
    }

    public function delete(int|string $attachmentId, SireUserIdentity $user): void
    {
        $path = (string) $attachmentId;

        if (! $this->isSafePath($path)) {
            return;
        }

        Storage::disk((string) config('sire.attachments.disk'))->delete($path);
    }

    /** An id is a relative path under the SIRE root, and nothing else. */
    private function isSafePath(string $path): bool
    {
        $root = trim((string) config('sire.attachments.path'), '/');

        return str_starts_with($path, $root.'/') && ! str_contains($path, '..');
    }

    private function urlFor(string $path): ?string
    {
        try {
            return Storage::disk((string) config('sire.attachments.disk'))->url($path);
        } catch (\Throwable $e) {
            // A private disk has no public URL, which is correct and not an
            // error. SIRE serves the file through its own authorized endpoint.
            return null;
        }
    }

    private function directory(object $owner): string
    {
        return sprintf(
            '%s/%d/%s/%d',
            trim((string) config('sire.attachments.path'), '/'),
            // $owner is an Eloquent Report, whose attribute is tenant_id --
            // tenantId is the DTO's spelling and is always null here, so every
            // upload was landing in sire/0/ regardless of tenant. That voided the
            // structural guarantee this class documents above: files were only
            // kept apart by report id, not by tenant.
            (int) ($owner->tenant_id ?? $owner->tenantId ?? 0),
            str_replace('\\', '-', class_basename($owner)),
            (int) ($owner->id ?? 0),
        );
    }

    private function guard(UploadedFile $file): void
    {
        $maxKb = (int) config('sire.attachments.max_kb');

        if ($maxKb > 0 && $file->getSize() > $maxKb * 1024) {
            abort(422, "Attachment is larger than {$maxKb} KB.");
        }

        $accept = (array) config('sire.attachments.accept');

        if ($accept !== [] && ! in_array($file->getMimeType(), $accept, true)) {
            abort(422, 'That file type is not accepted.');
        }
    }
}
