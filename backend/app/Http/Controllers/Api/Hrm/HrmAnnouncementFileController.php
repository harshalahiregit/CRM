<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file attached to a notification, for the app.
 *
 * Signed rather than authenticated: the app hands these URLs to a viewer or a
 * browser, neither of which sends an Authorization header. That is the same
 * reason the punch selfies are signed, and it is why the link expires — a
 * company circular should not be reachable forever by anyone who once had the
 * URL.
 *
 * Addressed by notification and position rather than by path. A path in a URL is
 * user input, and the only safe amount of trust to place in it is none.
 */
class HrmAnnouncementFileController extends Controller
{
    public function show(Notification $notification, int $index): Response
    {
        $attachments = $notification->attachments ?? [];
        $file = array_values($attachments)[$index] ?? null;

        abort_unless($file && ! empty($file['path']) && Storage::disk('local')->exists($file['path']), 404);

        return response()->file(
            Storage::disk('local')->path($file['path']),
            ['Content-Disposition' => 'inline; filename="'.addslashes($file['name'] ?? 'attachment').'"'],
        );
    }
}
