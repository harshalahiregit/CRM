<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Services\Shared\KickoffMeetingService;
use App\Support\Shared\KickoffSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Unauthenticated vendor acknowledgement of the minutes.
 *
 * The 48-char token is the credential — the vendor's signatory has no CRM login.
 * The blast radius is deliberately small: the token resolves to exactly one
 * meeting, the response carries only what is needed to acknowledge (title,
 * minutes, attendees) and never the wider tenant, and the token is burned on
 * acknowledgement so the link is single-use.
 *
 * Mirrors the public gate-scan and checklist-fill blocks: same token shape,
 * same throttle, no auth.
 */
class PublicKickoffController extends Controller
{
    public function __construct(private KickoffMeetingService $kickoffService) {}

    /**
     * GET /kickoff/ack/{token}/mom — the generated MOM PDF, inline.
     *
     * Lets a vendor READ the minutes before signing them; acknowledging blind was
     * the only option before. Streams the one file the meeting already owns, so
     * there is no second copy and nothing to keep in step: a MOM regenerated after
     * an attendance change is what this returns.
     *
     * The token is the only identifier in the URL — no storage path, no meeting
     * id. The filename is deliberately generic for the same reason.
     */
    public function mom(string $token)
    {
        $meeting = $this->kickoffService->resolveMomByToken($token);

        // Sent → Viewed → Acknowledged: the vendor opening the minutes is the
        // "viewed" signal (Meeting.docx §13). First view only; idempotent.
        $this->kickoffService->markMomViewed($meeting);

        return Storage::disk('kickoff_docs')->response(
            $meeting->mom_path,
            'Kickoff-Minutes.pdf',
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }

    /** The minutes to acknowledge. Read-only. */
    public function show(string $token)
    {
        $meeting = $this->kickoffService->resolveByToken($token);

        // Opening the acknowledgement page is a "viewed" signal too. Idempotent.
        $this->kickoffService->markMomViewed($meeting);

        return response()->json([
            'id' => $meeting->id,
            'title' => $meeting->title,
            'reference' => $meeting->reference,
            'agenda' => $meeting->agenda,
            'minutes' => $meeting->minutes,
            'scheduled_at' => $meeting->scheduled_at,
            'completed_at' => $meeting->completed_at,
            'has_document' => (bool) $meeting->mom_path,
            'subject' => $meeting->kickoffable_type ? [
                'label' => KickoffSubject::label(KickoffSubject::keyFor($meeting->kickoffable_type)),
                'name' => KickoffSubject::nameOf($meeting->kickoffable),
            ] : null,
            'attendees' => $meeting->attendees->map(fn ($a) => [
                'name' => $a->name,
                'organisation' => $a->organisation,
                'role' => $a->role,
                'attended' => $a->attended,
            ]),
            'acknowledged_at' => $meeting->acknowledged_at,
            'acknowledged_by_name' => $meeting->acknowledged_by_name,
        ]);
    }

    public function acknowledge(Request $request, string $token)
    {
        $meeting = $this->kickoffService->resolveByToken($token);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            // Optional response, same field the portal flow stores.
            'comment' => 'nullable|string|max:5000',
            // How the vendor is responding (Meeting.docx §13). Defaults to a plain
            // acknowledgement when absent; dispute/correction require a comment
            // (enforced in the service).
            'response_type' => 'nullable|in:acknowledge,dispute,correction',
        ]);

        $this->kickoffService->acknowledge($meeting, $data, ['ip' => $request->ip()]);

        $message = match ($data['response_type'] ?? 'acknowledge') {
            'dispute' => 'Thank you — your dispute has been recorded and sent to the coordinator.',
            'correction' => 'Thank you — your correction request has been sent to the coordinator.',
            default => 'Thank you — the minutes have been acknowledged.',
        };

        return response()->json(['message' => $message]);
    }
}
