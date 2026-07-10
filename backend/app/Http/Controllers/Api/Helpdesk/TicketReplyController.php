<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketReplyController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    /* ── Thread for a ticket ───────────────────────────────────── */
    public function index(Request $request, int $ticket)
    {
        $replies = $this->helpdesk->listReplies($ticket, $request->user()->tenant_id);

        return $this->success($replies, 'Replies retrieved');
    }

    /* ── Post a reply (multipart, with optional file uploads) ───── */
    public function store(Request $request, int $ticket)
    {
        $request->validate([
            'sender_type'   => ['required', 'in:client,admin,agent'],
            'sender_id'     => ['nullable', 'integer', 'min:1'],
            'message'       => ['required', 'string'],
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

        $reply = $this->helpdesk->addReply($ticket, [
            'sender_type' => $request->input('sender_type'),
            'sender_id'   => $request->input('sender_id'),
            'message'     => $request->input('message'),
            'attachments' => $stored,
        ], $tenantId);

        return $this->success($reply, 'Reply posted', 201);
    }

    /* ── Secure attachment download (auth + tenant scoped) ─────── */
    public function download(Request $request, int $ticket, int $attachment)
    {
        $file = $this->helpdesk->findAttachment($attachment, $ticket, $request->user()->tenant_id);

        abort_unless(Storage::disk('local')->exists($file->file_path), 404, 'File missing from storage.');

        return Storage::disk('local')->download($file->file_path, $file->file_name);
    }
}
