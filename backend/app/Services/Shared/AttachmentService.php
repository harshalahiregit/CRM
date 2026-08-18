<?php

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use App\Models\Shared\Attachment;
use App\Models\Shared\AttachmentFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The folder-tree file area, addressed by model class.
 *
 * Same shape as NoteService and ReminderService: the subject comes from the
 * route, never the payload, so nothing can be filed against another record by
 * editing a request.
 *
 * Bytes live on the `attachments` disk. Google Drive and OneDrive are import
 * sources handled entirely client-side — the picker downloads the file and posts
 * it here like any other upload, so there is one storage path and one set of
 * rules regardless of where a file came from.
 */
class AttachmentService
{
    public const DISK = 'attachments';

    /** Executables and scripts have no business in a document store. */
    private const BLOCKED_EXTENSIONS = [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl',
        'js', 'jse', 'vbs', 'vbe', 'ws', 'wsf', 'wsh', 'ps1', 'psm1',
        'jar', 'app', 'sh', 'php', 'phtml', 'phar',
    ];

    /* ── Reading ──────────────────────────────────────────────────────── */

    /**
     * One level of the tree: the folders and files directly inside $folderId
     * (null = root), plus the breadcrumb trail to get there.
     *
     * Deliberately one level rather than the whole tree — a vendor's file area
     * can grow without bound, and a browser only ever shows one level at a time.
     */
    public function browse(string $subjectClass, int $subjectId, int $tenantId, ?int $folderId = null): array
    {
        $folder = $folderId ? $this->folder($subjectClass, $subjectId, $tenantId, $folderId) : null;

        $folders = AttachmentFolder::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->where('parent_id', $folder?->id)
            ->withCount(['children', 'files'])
            ->orderBy('name')
            ->get();

        $files = Attachment::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->where('folder_id', $folder?->id)
            ->with('uploader:id,name')
            ->orderBy('name')
            ->get();

        return [
            'folder'      => $folder,
            'breadcrumbs' => $this->breadcrumbs($folder),
            'folders'     => $folders,
            'files'       => $files,
        ];
    }

    /** Root → … → current, so the UI can offer a path to click back through. */
    private function breadcrumbs(?AttachmentFolder $folder): array
    {
        $trail = [];
        // Bounded walk: a corrupted parent chain must not spin forever.
        for ($i = 0; $folder && $i < 50; $i++) {
            array_unshift($trail, ['id' => $folder->id, 'name' => $folder->name]);
            $folder = $folder->parent;
        }

        return $trail;
    }

    /* ── Folders ──────────────────────────────────────────────────────── */

    public function createFolder(string $subjectClass, int $subjectId, int $tenantId, array $data, User $actor): AttachmentFolder
    {
        $parent = ! empty($data['parent_id'])
            ? $this->folder($subjectClass, $subjectId, $tenantId, (int) $data['parent_id'])
            : null;

        $name = $this->cleanName($data['name']);
        $this->assertFolderNameFree($subjectClass, $subjectId, $tenantId, $parent?->id, $name);

        return AttachmentFolder::create([
            'tenant_id'       => $tenantId,
            'attachable_type' => $subjectClass,
            'attachable_id'   => $subjectId,
            'parent_id'       => $parent?->id,
            'name'            => $name,
            'created_by'      => $actor->id,
        ]);
    }

    public function renameFolder(AttachmentFolder $folder, string $name): AttachmentFolder
    {
        $name = $this->cleanName($name);
        $this->assertFolderNameFree(
            $folder->attachable_type, (int) $folder->attachable_id, (int) $folder->tenant_id,
            $folder->parent_id, $name, $folder->id,
        );

        $folder->update(['name' => $name]);

        return $folder->fresh();
    }

    /**
     * Delete a folder and everything under it.
     *
     * The FK cascades the rows; the BYTES have to be removed deliberately, or the
     * disk keeps growing with files nothing points at any more.
     */
    public function deleteFolder(AttachmentFolder $folder): void
    {
        DB::transaction(function () use ($folder) {
            foreach ($this->descendantIds($folder) as $id) {
                Attachment::withTrashed()->where('folder_id', $id)->get()
                    ->each(fn (Attachment $a) => $this->forget($a));
            }
            $folder->delete();
        });
    }

    /** The folder and every folder beneath it, breadth-first and bounded. */
    private function descendantIds(AttachmentFolder $folder): array
    {
        $ids = [$folder->id];
        $frontier = [$folder->id];

        for ($depth = 0; $frontier !== [] && $depth < 50; $depth++) {
            $frontier = AttachmentFolder::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /* ── Files ────────────────────────────────────────────────────────── */

    public function upload(
        string $subjectClass, int $subjectId, int $tenantId,
        UploadedFile $file, ?int $folderId, array $meta, User $actor,
    ): Attachment {
        $folder = $folderId ? $this->folder($subjectClass, $subjectId, $tenantId, $folderId) : null;

        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            throw new BusinessException("Files of type .{$ext} cannot be stored here.", 422);
        }

        $source = $meta['source'] ?? 'upload';
        if (! in_array($source, Attachment::SOURCES, true)) {
            $source = 'upload';
        }

        // Stored under an opaque name: the display name is a column, so two files
        // can share a name and a crafted filename cannot escape the directory.
        $path = $file->storeAs(
            "t{$tenantId}/".Str::slug(class_basename($subjectClass))."-{$subjectId}",
            Str::uuid().($ext ? ".{$ext}" : ''),
            self::DISK,
        );

        return Attachment::create([
            'tenant_id'       => $tenantId,
            'attachable_type' => $subjectClass,
            'attachable_id'   => $subjectId,
            'folder_id'       => $folder?->id,
            'name'            => $this->cleanName($meta['name'] ?? $file->getClientOriginalName()),
            'disk'            => self::DISK,
            'path'            => $path,
            'mime'            => $file->getClientMimeType(),
            'size'            => $file->getSize(),
            'source'          => $source,
            'source_ref'      => $meta['source_ref'] ?? null,
            'uploaded_by'     => $actor->id,
        ]);
    }

    public function renameFile(Attachment $file, string $name): Attachment
    {
        $file->update(['name' => $this->cleanName($name)]);

        return $file->fresh();
    }

    /** Move a file into another folder of the SAME subject, or to the root. */
    public function moveFile(Attachment $file, ?int $folderId): Attachment
    {
        $folder = $folderId
            ? $this->folder($file->attachable_type, (int) $file->attachable_id, (int) $file->tenant_id, $folderId)
            : null;

        $file->update(['folder_id' => $folder?->id]);

        return $file->fresh();
    }

    public function deleteFile(Attachment $file): void
    {
        $this->forget($file);
    }

    /** Bytes off the disk, then the row. */
    private function forget(Attachment $file): void
    {
        if ($file->path && Storage::disk($file->disk ?: self::DISK)->exists($file->path)) {
            Storage::disk($file->disk ?: self::DISK)->delete($file->path);
        }
        $file->forceDelete();
    }

    /** Absolute path + display name for a download response. */
    public function download(Attachment $file): array
    {
        $disk = Storage::disk($file->disk ?: self::DISK);

        if (! $file->path || ! $disk->exists($file->path)) {
            throw new BusinessException('That file is no longer on the server.', 404);
        }

        return [
            'path'     => $disk->path($file->path),
            'filename' => $file->name,
            'mime'     => $file->mime ?: 'application/octet-stream',
        ];
    }

    /* ── Guards ───────────────────────────────────────────────────────── */

    /**
     * A folder id that isn't this subject's reads as absent.
     *
     * Without this, a folder id from another vendor would happily accept an
     * upload — the subject in the route would be decoration.
     */
    private function folder(string $subjectClass, int $subjectId, int $tenantId, int $id): AttachmentFolder
    {
        return AttachmentFolder::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->whereKey($id)
            ->first() ?? throw new BusinessException('Folder not found.', 404);
    }

    private function assertFolderNameFree(
        string $subjectClass, int $subjectId, int $tenantId, ?int $parentId, string $name, ?int $exceptId = null,
    ): void {
        $clash = AttachmentFolder::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($clash) {
            throw new BusinessException("A folder called “{$name}” is already here.", 422);
        }
    }

    /** Strip path separators and control characters from a display name. */
    private function cleanName(string $name): string
    {
        $clean = trim(preg_replace('#[/\\\\\x00-\x1F]+#', ' ', $name));
        $clean = preg_replace('/\s+/', ' ', $clean);

        return $clean !== '' ? mb_substr($clean, 0, 200) : 'Untitled';
    }
}
