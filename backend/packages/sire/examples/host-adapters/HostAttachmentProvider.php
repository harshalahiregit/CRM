<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireAttachmentProvider;
use App\Support\Sire\Sdk\SireAttachment;
use App\Support\Sire\Sdk\SireUserIdentity;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * HostAttachmentProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the file/attachment service, with its disk, size limits and scanning
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['attachment' => \App\Sire\Host\HostAttachmentProvider::class],
 *
 * Until you do, SIRE runs on its own implementation and everything keeps
 * working. Wiring one provider at a time is the expected path, not a compromise.
 *
 * Every method throws until you replace it. That is deliberate: a half-finished
 * provider should stop with a message naming the method, not quietly return an
 * empty array that the UI renders as "nothing here".
 *
 * WATCH OUT
 *
 * Enforce tenant isolation, authorization, size and SNIFFED mime type, and safe
 * filenames. Treat the attachment id as an opaque handle: if ids are paths,
 * '../../.env' is a valid attachment id. Never put a disk name or storage key in
 * the descriptor.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostAttachmentProvider implements SireAttachmentProvider
{
    public function store(object $owner, UploadedFile $file, SireUserIdentity $user, array $meta = []): SireAttachment
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAttachmentProvider::store() is not implemented. Either finish it, or point '
            ."config('sire.providers.attachment') back at SIRE's own provider."
        );
    }

    public function listFor(object $owner): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAttachmentProvider::listFor() is not implemented. Either finish it, or point '
            ."config('sire.providers.attachment') back at SIRE's own provider."
        );
    }

    public function find(int|string $attachmentId): ?SireAttachment
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAttachmentProvider::find() is not implemented. Either finish it, or point '
            ."config('sire.providers.attachment') back at SIRE's own provider."
        );
    }

    public function delete(int|string $attachmentId, SireUserIdentity $user): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAttachmentProvider::delete() is not implemented. Either finish it, or point '
            ."config('sire.providers.attachment') back at SIRE's own provider."
        );
    }
}
