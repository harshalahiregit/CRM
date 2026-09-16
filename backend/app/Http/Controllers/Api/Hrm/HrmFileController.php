<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Shared\Attachment;
use App\Services\Shared\AttachmentService;

/**
 * Serving a receipt or a bill to the app.
 *
 * Reached by a SIGNED url rather than a token. The app hands these straight to
 * an image widget, which sends no Authorization header, so a normal protected
 * route would answer 401 and the receipt would render as a broken image.
 *
 * The signature is the permission: it is issued only inside a payload the
 * employee was already allowed to see, covers exactly one attachment id, and
 * expires. Laravel refuses a tampered or stale one before this method runs.
 */
class HrmFileController extends Controller
{
    public function __construct(private AttachmentService $attachments)
    {
    }

    public function show(Attachment $attachment)
    {
        $f = $this->attachments->download($attachment);

        return response()->file($f['path'], ['Content-Type' => $f['mime']]);
    }
}
