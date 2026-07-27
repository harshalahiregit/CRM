<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Shared\KickoffMeeting;
use App\Services\Shared\OnlineMeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages online-meeting link generation and retrieval for kickoff meetings.
 *
 * Routes (added to routes/shared.php inside the auth:sanctum group):
 *   POST  /kickoff/meetings/{kickoffMeeting}/generate-link
 *   GET   /kickoff/meetings/{kickoffMeeting}/link
 */
class KickoffMeetingLinkController extends Controller
{
    public function __construct(private OnlineMeetingService $meetingService) {}

    /**
     * Generate (or regenerate) an online meeting link.
     *
     * Body (optional):
     *   platform  — 'google_meet' | 'zoom' | 'teams' | 'stub'
     *               If omitted, falls back to MEETING_PROVIDER in .env.
     */
    public function generate(Request $request, KickoffMeeting $kickoffMeeting): JsonResponse
    {
        // Scope to tenant
        abort_unless(
            $kickoffMeeting->tenant_id === auth()->user()->tenant_id,
            403
        );

        $request->validate([
            'platform' => ['nullable', 'string', 'in:google_meet,zoom,teams,stub'],
        ]);

        try {
            $result = $this->meetingService->createMeeting(
                $kickoffMeeting,
                $request->input('platform')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'meeting' => $kickoffMeeting->fresh(),
            'link'    => $result,
        ]);
    }

    /**
     * Return the stored online-meeting link data (read-only).
     */
    public function show(KickoffMeeting $kickoffMeeting): JsonResponse
    {
        abort_unless(
            $kickoffMeeting->tenant_id === auth()->user()->tenant_id,
            403
        );

        $data = $this->meetingService->getLinkData($kickoffMeeting);

        if (! $data) {
            return response()->json(['message' => 'No online meeting link found for this meeting.'], 404);
        }

        return response()->json($data);
    }
}
