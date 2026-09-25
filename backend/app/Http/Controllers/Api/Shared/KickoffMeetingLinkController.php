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
     *   platform  — 'google_meet' | 'zoom' | 'teams'
     *               If omitted, falls back to the meeting's stored platform,
     *               then to OnlineMeetingService::DEFAULT_PLATFORM.
     */
    public function generate(Request $request, KickoffMeeting $kickoffMeeting): JsonResponse
    {
        // Scope to tenant
        abort_unless(
            $kickoffMeeting->tenant_id === auth()->user()->tenant_id,
            403
        );

        // ACCEPTED is wider than PLATFORMS on purpose: meetings scheduled
        // before the in-app room was retired still hold 'jitsi' or 'stub', and
        // this page posts the stored platform straight back. They are
        // normalised to a real platform rather than rejected.
        $request->validate([
            'platform' => ['nullable', 'string', \Illuminate\Validation\Rule::in(OnlineMeetingService::ACCEPTED)],
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
     * The organiser pastes the real room link.
     *
     * Body: link — a Google Meet, Zoom or Teams room URL. The platforms'
     * "start a new meeting" URLs are refused: they open an empty room for
     * each person, which is exactly what this exists to replace.
     */
    public function update(Request $request, KickoffMeeting $kickoffMeeting, \App\Services\Shared\MeetingAttendanceGate $gate): JsonResponse
    {
        abort_unless($kickoffMeeting->tenant_id === $request->user()->tenant_id, 403);
        abort_unless($gate->hosts($kickoffMeeting, $request->user()), 403, 'Only the organiser or an admin can set the meeting link.');

        $data = $request->validate(['link' => ['required', 'string', 'max:2048']]);

        try {
            $link = $this->meetingService->setLink($kickoffMeeting, $data['link']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['link' => [$e->getMessage()]]], 422);
        }

        // Everyone invited gets the room, now, by e-mail — see
        // MeetingLinkAnnouncer. The send runs after the response is flushed so
        // pasting a link never waits on an SMTP session per participant; the
        // counts returned are the plan, which is the half the organiser has to
        // act on ("two of these people have no address anywhere").
        $notified = app(\App\Services\Shared\MeetingLinkAnnouncer::class)
            ->announceAfterResponse($kickoffMeeting, $request->user());

        return response()->json([
            'meeting' => $kickoffMeeting->fresh(),
            'link' => $link,
            'notified' => $notified,
        ]);
    }

    /**
     * Send the room link again, to everybody.
     *
     * Somebody always joins late, loses the mail, or is added to the roster
     * after the link went out. Re-pasting the same URL to trigger the send would
     * work but reads as a mistake; this says what it does.
     */
    public function announce(Request $request, KickoffMeeting $kickoffMeeting, \App\Services\Shared\MeetingAttendanceGate $gate): JsonResponse
    {
        abort_unless($kickoffMeeting->tenant_id === $request->user()->tenant_id, 403);
        abort_unless($gate->hosts($kickoffMeeting, $request->user()), 403, 'Only the organiser or an admin can send the meeting link.');

        $announcer = app(\App\Services\Shared\MeetingLinkAnnouncer::class);

        if (! $kickoffMeeting->meeting_link || OnlineMeetingService::isInstant($kickoffMeeting->meeting_link)) {
            return response()->json([
                'message' => 'Start the meeting and paste the room link first — there is no room to send yet.',
            ], 422);
        }

        return response()->json(['notified' => $announcer->announceAfterResponse($kickoffMeeting, $request->user())]);
    }

    /**
     * Return the stored online-meeting link data (read-only).
     */
    public function show(KickoffMeeting $kickoffMeeting, \App\Services\Shared\MeetingAttendanceGate $gate): JsonResponse
    {
        abort_unless(
            $kickoffMeeting->tenant_id === auth()->user()->tenant_id,
            403
        );

        $data = $this->meetingService->getLinkData($kickoffMeeting);

        if (! $data) {
            return response()->json(['message' => 'No online meeting link found for this meeting.'], 404);
        }

        // Same gate as the meeting payload. This endpoint is the other way into
        // the link, and leaving it open would have made the first one decorative:
        // a staff attendee who is not the organiser gets link => null here until
        // they mark attendance. See MeetingAttendanceGate.
        return response()->json(array_merge(
            $data,
            $gate->stateFor($kickoffMeeting, auth()->user()),
            ['link' => $gate->linkFor($kickoffMeeting, auth()->user())],
        ));
    }

    /**
     * Mark attendance, and get the link in return.
     *
     * The staff half of what the two portals already do. Without it an internal
     * attendee could never be recorded as present — the register would carry the
     * vendors who marked attendance in the portal and nobody from our side,
     * which reads as a meeting the vendor attended alone.
     */
    public function markAttendance(Request $request, KickoffMeeting $kickoffMeeting, \App\Services\Shared\MeetingAttendanceGate $gate): JsonResponse
    {
        abort_unless(
            $kickoffMeeting->tenant_id === $request->user()->tenant_id,
            403
        );

        $user = $request->user();

        // Staff are not always on the roster either — the organiser adds the
        // people they expect to speak, not everyone who attends. The identity is
        // the authenticated account, so nothing is guessed.
        return response()->json($gate->mark($kickoffMeeting, $user, $request, [
            'name' => $user->name,
            'email' => $user->email,
            'side' => 'internal',
        ]));
    }
}
