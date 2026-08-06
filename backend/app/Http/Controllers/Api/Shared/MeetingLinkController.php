<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Shared\MeetingLinkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ad-hoc meeting links for the message composers (owner: Shivam). The composer's
 * "Meeting" button POSTs a platform and gets back a real, usable link to drop
 * into the message. See MeetingLinkService for the real-vs-instant logic.
 */
class MeetingLinkController extends Controller
{
    use ApiResponse;

    public function __construct(private MeetingLinkService $meetings)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(MeetingLinkService::PLATFORMS)],
            'title'    => ['nullable', 'string', 'max:200'],
        ]);

        $link = $this->meetings->forPlatform(
            $data['platform'],
            $request->user()->tenant_id,
            $data['title'] ?? 'CRM meeting',
        );

        return $this->success($link, 'Meeting link ready');
    }
}
