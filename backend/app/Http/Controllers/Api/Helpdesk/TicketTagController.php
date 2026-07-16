<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\Helpdesk\TicketTagService;
use Illuminate\Http\Request;

/**
 * Phase 3 — ticket tags (tenant-wide colored labels) + per-ticket attach/detach.
 */
class TicketTagController extends Controller
{
    use ApiResponse;

    public function __construct(private TicketTagService $tags, private HelpdeskService $helpdesk)
    {
    }

    private function guardView(Request $request, int $ticket): void
    {
        $this->helpdesk->assertTicketVisible($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role);
    }

    public function index(Request $request)
    {
        return $this->success($this->tags->listTags($request->user()->tenant_id), 'Tags retrieved');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:50', 'color' => 'nullable|string|max:9']);

        return $this->success($this->tags->createTag($data, $request->user()->tenant_id), 'Tag created', 201);
    }

    public function ticketTags(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        return $this->success($this->tags->ticketTags($ticket, $request->user()->tenant_id), 'Ticket tags retrieved');
    }

    public function attach(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $data = $request->validate([
            'tag_id' => 'nullable|integer',
            'name'   => 'nullable|string|max:50',
            'color'  => 'nullable|string|max:9',
        ]);
        if (empty($data['tag_id']) && empty($data['name'])) {
            return $this->error('Provide a tag_id or a name.', 422);
        }

        return $this->success($this->tags->attach($ticket, $data, $request->user()->tenant_id), 'Tag added');
    }

    public function detach(Request $request, int $ticket, int $tagId)
    {
        $this->guardView($request, $ticket);
        return $this->success($this->tags->detach($ticket, $tagId, $request->user()->tenant_id), 'Tag removed');
    }
}
