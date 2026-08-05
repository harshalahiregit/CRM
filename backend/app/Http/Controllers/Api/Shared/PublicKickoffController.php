<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Services\Shared\KickoffMeetingService;
use App\Support\Shared\KickoffSubject;
use Illuminate\Http\Request;

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
    public function __construct(private KickoffMeetingService $kickoffService)
    {
    }

    /** The minutes to acknowledge. Read-only. */
    public function show(string $token)
    {
        $meeting = $this->kickoffService->resolveByToken($token);

        return response()->json([
            'id'              => $meeting->id,
            'title'          => $meeting->title,
            'reference'      => $meeting->reference,
            'agenda'         => $meeting->agenda,
            'minutes'        => $meeting->minutes,
            'scheduled_at'   => $meeting->scheduled_at,
            'completed_at'   => $meeting->completed_at,
            'has_document'   => (bool) $meeting->mom_path,
            'subject'        => $meeting->kickoffable_type ? [
                'label' => KickoffSubject::label(KickoffSubject::keyFor($meeting->kickoffable_type)),
                'name'  => KickoffSubject::nameOf($meeting->kickoffable),
            ] : null,
            'attendees'      => $meeting->attendees->map(fn ($a) => [
                'name'         => $a->name,
                'organisation' => $a->organisation,
                'role'         => $a->role,
                'attended'     => $a->attended,
            ]),
            'acknowledged_at' => $meeting->acknowledged_at,
        ]);
    }

    public function acknowledge(Request $request, string $token)
    {
        $meeting = $this->kickoffService->resolveByToken($token);
        $data    = $request->validate([
            'name'    => 'required|string|max:120',
            // Optional response, same field the portal flow stores.
            'comment' => 'nullable|string|max:5000',
        ]);

        $this->kickoffService->acknowledge($meeting, $data, ['ip' => $request->ip()]);

        return response()->json(['message' => 'Thank you — the minutes have been acknowledged.']);
    }
}
