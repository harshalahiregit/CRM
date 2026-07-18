<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketReplyController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    private function guardView(Request $request, int $ticket): void
    {
        $this->helpdesk->assertTicketVisible($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role, $request->user()->email);
    }

    /* ── Thread for a ticket ───────────────────────────────────── */
    public function index(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $replies = $this->helpdesk->listReplies($ticket, $request->user()->tenant_id);

        return $this->success($replies, 'Replies retrieved');
    }

    /* ── Post a reply (multipart, with optional file uploads) ───── */
    public function store(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        // Drop any Cc entry that isn't a valid email BEFORE validation. A stray or
        // browser-autofilled value (e.g. a name) would otherwise 422 the entire
        // reply — making it look like "sending a message doesn't work". Cc is a
        // best-effort convenience field; a bad address should never block the reply.
        $request->merge([
            'cc' => collect($request->input('cc', []))
                ->filter(fn ($e) => is_string($e) && filter_var(trim($e), FILTER_VALIDATE_EMAIL))
                ->map(fn ($e) => trim($e))
                ->values()
                ->all(),
        ]);

        // sender_type / sender_id are NOT accepted from the request. They used to
        // be, which let any caller stamp a reply as another role or another user
        // — and the composer sent 'admin' for everyone, so every agent's replies
        // were already mislabelled. Identity comes from the token, full stop.
        $request->validate([
            'message'       => ['required', 'string'],
            'cc'            => ['nullable', 'array', 'max:20'],
            'cc.*'          => ['email'],
            'attachments'   => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],   // 10 MB each
        ]);

        $tenantId = $request->user()->tenant_id;

        // Store each uploaded file on the private local disk, then hand the
        // resulting path + original name to the service. Files live under
        // storage/app/private/helpdesk/attachments/{tenant}/{ticket}/ — never
        // web-accessible; downloads go through the authenticated route below.
        $stored = [];
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("helpdesk/attachments/{$tenantId}/{$ticket}", 'local');
            $stored[] = [
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
            ];
        }

        // guardView() has already rejected portal roles, so a reply arriving here
        // is always staff. 'admin' stays admin; every other internal role posts as
        // an agent. Only the inbound-email path may author a 'client' reply, and
        // it calls the service directly rather than coming through here.
        // The composer now sends rich-text HTML. It is stored as-is and later
        // rendered as HTML in the CRM thread AND in outbound email, so it MUST be
        // sanitized to a strict allowlist here (DOMDocument, not regex) before it
        // ever reaches the database.
        $reply = $this->helpdesk->addReply($ticket, [
            'sender_type' => $request->user()->role === 'admin' ? 'admin' : 'agent',
            'sender_id'   => $request->user()->id,
            'message'     => HtmlSanitizer::clean($request->input('message')),
            'cc'          => $request->input('cc', []),
            'attachments' => $stored,
        ], $tenantId);

        return $this->success($reply, 'Reply posted', 201);
    }

    /* ── Secure attachment download (auth + tenant scoped) ─────── */
    public function download(Request $request, int $ticket, int $attachment)
    {
        $this->guardView($request, $ticket);
        $file = $this->helpdesk->findAttachment($attachment, $ticket, $request->user()->tenant_id);

        abort_unless(Storage::disk('local')->exists($file->file_path), 404, 'File missing from storage.');

        return Storage::disk('local')->download($file->file_path, $file->file_name);
    }
}
