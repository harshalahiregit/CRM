<?php

namespace App\Http\Controllers\Api\Compliance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compliance\SubmitChecklistRequest;
use App\Services\Compliance\ComplianceChecklistService;
use Illuminate\Http\Request;

/**
 * Unauthenticated fill-in, per the brief: a checklist goes to a vendor's site
 * supervisor who has no CRM login. The 48-char token is the credential.
 *
 * The blast radius is deliberately small:
 *  - the token resolves to exactly ONE checklist, never a listing;
 *  - the response carries the questions and this checklist's own answers, and
 *    nothing else about the tenant — no vendor record, no user, no siblings;
 *  - the token is cleared when the checklist closes and re-minted on reopen, so
 *    an old link in an inbox is dead rather than dormant;
 *  - writes are refused unless the checklist is Assigned, so a link cannot be
 *    replayed to alter an approved record;
 *  - scoring happens server-side, so a filler cannot post their own risk band.
 *
 * Mirrors the public gate-scan block: same token shape, same throttle, no auth.
 */
class PublicChecklistController extends Controller
{
    public function __construct(private ComplianceChecklistService $checklistService)
    {
    }

    /** The form to fill. Read-only; never mutates. */
    public function show(string $token)
    {
        $checklist = $this->checklistService->resolveByToken($token);

        return response()->json($this->checklistService->form($checklist));
    }

    /** Save progress — a site walk is not a single request. */
    public function save(Request $request, string $token)
    {
        $checklist = $this->checklistService->resolveByToken($token);
        $data      = $request->validate(['responses' => 'required|array']);

        $this->checklistService->saveResponses($checklist, $data['responses']);

        return response()->json(['message' => 'Progress saved']);
    }

    public function submit(SubmitChecklistRequest $request, string $token)
    {
        $checklist = $this->checklistService->resolveByToken($token);

        $this->checklistService->submit(
            $checklist,
            $request->validated() + ['selfie' => $request->file('selfie')],
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            actor: null,   // no login on this path — the audit records the link
        );

        // Deliberately terse: the filler gets confirmation, not the score. The
        // risk band is for the approval chain, and echoing it here would let
        // anyone with the link probe the scoring rules by resubmitting.
        return response()->json(['message' => 'Checklist submitted. Thank you.']);
    }
}
